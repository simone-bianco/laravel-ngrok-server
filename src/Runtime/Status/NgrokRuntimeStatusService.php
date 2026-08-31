<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Status;

use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDirectStatusProjection;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeStatusSnapshotReader;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokSettingsGeneration;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final readonly class NgrokRuntimeStatusService
{
    public function __construct(
        private NgrokRuntimeManifestStore $manifests,
        private NgrokLifecycleLedgerStore $ledgers,
        private NgrokRuntimeStatusSnapshotReader $snapshots,
        private NgrokRuntimeSettingsSnapshotProvider $settings,
        private NgrokRuntimeLifecycleFence $fence,
        private NgrokRuntimeSpawnGate $gate,
        private NgrokSettingsGeneration $settingsGeneration,
        private NgrokRuntimeRemoteAccessPolicy $debugPolicy,
        private NgrokDirectStatusProjection $directStatus,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $settings = $this->safeSettings();
        $base = $this->base($settings);

        try {
            $manifest = $this->manifests->read();
        } catch (\Throwable) {
            return $this->terminal($base, 'recovery_required', 'manifest_corrupt');
        }

        if ($manifest === null) {
            try {
                $ledger = $this->ledgers->read();
            } catch (\Throwable) {
                $ledger = ['state' => 'unknown'];
            }

            return $ledger === null
                ? $this->terminal($base, 'bootstrap_required', 'runtime_not_initialized')
                : $this->terminal($base, 'recovery_required', 'manifest_missing_with_runtime_state');
        }

        try {
            $this->manifests->assertInitialized($manifest);
        } catch (\Throwable) {
            $reason = ($manifest['state'] ?? null) === 'initializing'
                ? 'bootstrap_incomplete'
                : 'manifest_invalid';

            return $this->terminal($base, 'recovery_required', $reason, $manifest);
        }

        $versionBeforeSnapshot = $this->safeCurrentVersion();
        try {
            $ledgerBeforeSnapshot = $this->ledgers->read();
            if ($ledgerBeforeSnapshot === null) {
                throw new \RuntimeException('Ngrok lifecycle ledger is missing.');
            }
            $this->ledgers->assertLinked($manifest, $ledgerBeforeSnapshot);
        } catch (\Throwable) {
            return $this->terminal($base, 'recovery_required', 'ledger_missing_corrupt_or_unlinked', $manifest);
        }

        try {
            $snapshot = $this->snapshots->read();
        } catch (\Throwable) {
            $snapshot = null;
        }
        try {
            $ledger = $this->ledgers->read();
            if ($ledger === null) {
                throw new \RuntimeException('Ngrok lifecycle ledger is missing.');
            }
            $this->ledgers->assertLinked($manifest, $ledger);
        } catch (\Throwable) {
            return $this->terminal($base, 'recovery_required', 'ledger_missing_corrupt_or_unlinked', $manifest);
        }
        $spawnGateEnabled = $this->spawnGateEnabled();
        $versionAfterSnapshot = $this->safeCurrentVersion();

        $ledgerState = is_string($ledger['state'] ?? null) ? $ledger['state'] : 'unknown';
        $runtimeStateStable = $this->sameLifecycleState($ledgerBeforeSnapshot, $ledger);
        $settingsCurrent = $runtimeStateStable
            && $settings !== null
            && $this->sameVersion($settings->version, $versionBeforeSnapshot)
            && $this->sameVersion($versionBeforeSnapshot, $versionAfterSnapshot);
        $supervisorFresh = $this->supervisorFresh($snapshot);
        $generationMatches = $settingsCurrent && $this->generationMatches($snapshot, $settings);
        $manifestMatches = data_get($snapshot, 'manifest.installation_runtime_id') === ($manifest['installation_runtime_id'] ?? null);
        $attemptMatches = data_get($snapshot, 'ledger.state') === $ledgerState
            && data_get($snapshot, 'ledger.state_sequence') === ($ledger['state_sequence'] ?? null)
            && data_get($snapshot, 'ledger.spawn_attempt_id') === ($ledger['spawn_attempt_id'] ?? null);
        $runtimeIdentityMatches = $manifestMatches
            && $attemptMatches
            && $this->runtimeIdentityMatches($snapshot, $ledger, $ledgerState);
        $readyEvidenceMatches = $this->readyEvidenceMatches($snapshot, $settings, $ledger, $ledgerState);
        $configurationReasons = $this->configurationReasons($settings);
        $summary = $this->deriveSummary(
            desired: $settings?->desiredEnabled ?? false,
            settingsCurrent: $settingsCurrent,
            ledgerState: $ledgerState,
            supervisorFresh: $supervisorFresh,
            generationMatches: $generationMatches,
            runtimeIdentityMatches: $runtimeIdentityMatches,
            readyEvidenceMatches: $readyEvidenceMatches,
            configurationReady: $configurationReasons === [],
            snapshotSummary: is_string($snapshot['summary'] ?? null) ? $snapshot['summary'] : null,
        );
        $reasons = array_values(array_unique([
            ...$configurationReasons,
            ...$this->snapshotReasons($snapshot),
            ...$this->summaryReasons($summary),
        ]));
        $canStart = $spawnGateEnabled
            && $ledgerState === 'clean'
            && ($settings?->desiredEnabled ?? false) === false
            && $settings !== null
            && $this->canonicalUpstream() !== null;
        $publicUrl = $summary === 'ready' && $readyEvidenceMatches && is_string(data_get($snapshot, 'edge.public_url'))
            ? data_get($snapshot, 'edge.public_url')
            : null;

        return array_replace_recursive($base, [
            'summary' => $summary,
            'direct' => $this->directStatus->project($settings),
            'ngrok_policy' => [
                'web_enabled' => $settings?->ngrokWebEnabled ?? false,
                'api_enabled' => $settings?->ngrokApiEnabled ?? false,
            ],
            'desired' => [
                'enabled' => $settings?->desiredEnabled ?? false,
                'version' => $this->versionArray($settings?->version),
            ],
            'configuration' => [
                'remote_access_enabled' => $settings?->remoteAccessEnabled ?? false,
                'approved_origin' => $settings?->externalOrigin?->value(),
                'upstream_url' => (string) config('ngrok-server.upstream_url'),
                'token' => [
                    'configured' => $settings?->authtoken !== null,
                    'source' => $settings?->authtokenSource ?? 'none',
                ],
            ],
            'manifest' => [
                'state' => 'initialized',
                'installation_runtime_id' => is_string($manifest['installation_runtime_id'] ?? null)
                    ? $manifest['installation_runtime_id']
                    : null,
            ],
            'ledger' => [
                'state' => $ledgerState,
                'state_sequence' => is_int($ledger['state_sequence'] ?? null) ? $ledger['state_sequence'] : null,
                'spawn_attempt_id' => is_string($ledger['spawn_attempt_id'] ?? null) ? $ledger['spawn_attempt_id'] : null,
            ],
            'token' => [
                'configured' => $settings?->authtoken !== null,
                'source' => $settings?->authtokenSource ?? 'none',
            ],
            'safety' => [
                'manifest_state' => 'initialized',
                'ledger_state' => $ledgerState,
                'recovery_required' => $summary === 'recovery_required',
                'generation_matches' => $generationMatches,
                'spawn_gate_enabled' => $spawnGateEnabled,
            ],
            'supervisor' => [
                'state' => $supervisorFresh ? 'online' : (data_get($snapshot, 'supervisor.heartbeat_at') === null ? 'unavailable' : 'stale'),
                'heartbeat_at' => is_string(data_get($snapshot, 'supervisor.heartbeat_at'))
                    ? data_get($snapshot, 'supervisor.heartbeat_at')
                    : null,
            ],
            'child' => [
                'state' => is_string(data_get($snapshot, 'child.state')) ? data_get($snapshot, 'child.state') : ($ledgerState === 'clean' ? 'absent' : 'unknown'),
                'pid' => is_int(data_get($snapshot, 'child.pid')) ? data_get($snapshot, 'child.pid') : null,
            ],
            'edge' => [
                'state' => is_string(data_get($snapshot, 'edge.state')) ? data_get($snapshot, 'edge.state') : 'unknown',
                'public_url' => $publicUrl,
            ],
            'tunnel' => [
                'state' => is_string(data_get($snapshot, 'edge.state')) ? data_get($snapshot, 'edge.state') : 'unknown',
                'public_url' => $publicUrl,
            ],
            'upstream' => [
                'state' => is_string(data_get($snapshot, 'upstream.state')) ? data_get($snapshot, 'upstream.state') : 'unknown',
                'url' => is_string(data_get($snapshot, 'upstream.url'))
                    ? data_get($snapshot, 'upstream.url')
                    : (string) config('ngrok-server.upstream_url'),
            ],
            'readiness' => [
                'state' => $summary,
                'reasons' => $reasons,
                'generation_matches' => $generationMatches,
            ],
            'diagnostics' => [
                'ngrok_version' => is_string(data_get($snapshot, 'diagnostics.ngrok_version'))
                    ? data_get($snapshot, 'diagnostics.ngrok_version')
                    : null,
                'agent_api_port' => is_int(data_get($snapshot, 'diagnostics.agent_api_port'))
                    ? data_get($snapshot, 'diagnostics.agent_api_port')
                    : null,
                'observed_at' => is_string($snapshot['observed_at'] ?? null) ? $snapshot['observed_at'] : null,
                'debug_mode' => $this->debugPolicy->debugEnabled(),
                'force_access_in_debug_mode' => $this->debugPolicy->overrideEnabled(),
                'debug_override_active' => $this->debugPolicy->overrideActive(),
            ],
            'actions' => [
                'can_start' => $canStart,
                'can_stop' => $settings?->desiredEnabled === true,
            ],
        ]);
    }

    private function spawnGateEnabled(): bool
    {
        try {
            return $this->fence->synchronized(fn (): bool => $this->gate->isEnabled());
        } catch (\Throwable) {
            return false;
        }
    }

    private function deriveSummary(
        bool $desired,
        bool $settingsCurrent,
        string $ledgerState,
        bool $supervisorFresh,
        bool $generationMatches,
        bool $runtimeIdentityMatches,
        bool $readyEvidenceMatches,
        bool $configurationReady,
        ?string $snapshotSummary,
    ): string {
        if (! in_array($ledgerState, ['clean', 'spawn_armed', 'child_active', 'stopping'], true)) {
            return 'recovery_required';
        }
        if (! $settingsCurrent) {
            return 'reconciling';
        }
        if (! $desired && $ledgerState === 'clean') {
            return 'stopped';
        }
        if (! $configurationReady) {
            return 'blocked';
        }
        if (! $supervisorFresh) {
            return $ledgerState === 'clean' ? 'supervisor_unavailable' : 'recovery_required';
        }
        if (! $generationMatches || ! $runtimeIdentityMatches) {
            return 'reconciling';
        }
        if ($ledgerState === 'stopping') {
            return 'stopping';
        }
        if ($snapshotSummary === 'ready') {
            return $readyEvidenceMatches ? 'ready' : 'reconciling';
        }
        if ($snapshotSummary !== null && in_array($snapshotSummary, [
            'starting', 'stopping', 'blocked', 'degraded', 'error', 'recovery_required',
        ], true)) {
            return $snapshotSummary;
        }

        return in_array($ledgerState, ['child_active', 'spawn_armed'], true) ? 'starting' : 'reconciling';
    }

    /** @param array<string, mixed>|null $snapshot @param array<string, mixed> $ledger */
    private function runtimeIdentityMatches(?array $snapshot, array $ledger, string $ledgerState): bool
    {
        if ($snapshot === null
            || ($snapshot['schema_version'] ?? null) !== (int) config('ngrok-server.snapshot_schema_version', 1)
            || ! is_string($snapshot['observed_at'] ?? null)
            || strtotime((string) $snapshot['observed_at']) === false
            || data_get($snapshot, 'manifest.state') !== 'initialized') {
            return false;
        }
        if ($ledgerState === 'clean') {
            return data_get($snapshot, 'child.state') === 'absent'
                && data_get($snapshot, 'child.pid') === null;
        }

        if (data_get($snapshot, 'child.endpoint_instance_id') !== ($ledger['endpoint_instance_id'] ?? null)
            || data_get($snapshot, 'supervisor.instance_id') !== ($ledger['supervisor_instance_id'] ?? null)
            || data_get($snapshot, 'supervisor.boot_epoch_id') !== ($ledger['boot_epoch_id'] ?? null)
            || data_get($snapshot, 'agent_api.address') !== ($ledger['agent_api_address'] ?? null)
            || data_get($snapshot, 'upstream.url') !== ($ledger['canonical_upstream'] ?? null)) {
            return false;
        }

        return ! in_array($ledgerState, ['child_active', 'stopping'], true)
            || data_get($snapshot, 'child.pid') === ($ledger['pid'] ?? null);
    }

    /** @param array<string, mixed>|null $snapshot @param array<string, mixed> $ledger */
    private function readyEvidenceMatches(
        ?array $snapshot,
        ?NgrokSettingsSnapshot $settings,
        array $ledger,
        string $ledgerState,
    ): bool {
        if ($snapshot === null || $settings?->externalOrigin === null || $ledgerState !== 'child_active') {
            return false;
        }
        $expectedOrigin = $settings->externalOrigin->value();
        $expectedUpstream = $this->canonicalUpstream();
        if ($expectedUpstream === null) {
            return false;
        }
        $pid = $ledger['pid'] ?? null;

        return ($snapshot['summary'] ?? null) === 'ready'
            && data_get($snapshot, 'readiness.state') === 'ready'
            && data_get($snapshot, 'child.state') === 'running'
            && is_int($pid)
            && $pid > 0
            && data_get($snapshot, 'child.pid') === $pid
            && data_get($snapshot, 'child.endpoint_instance_id') === ($ledger['endpoint_instance_id'] ?? null)
            && data_get($snapshot, 'supervisor.instance_id') === ($ledger['supervisor_instance_id'] ?? null)
            && data_get($snapshot, 'supervisor.boot_epoch_id') === ($ledger['boot_epoch_id'] ?? null)
            && data_get($snapshot, 'agent_api.state') === 'ready'
            && data_get($snapshot, 'agent_api.address') === ($ledger['agent_api_address'] ?? null)
            && data_get($snapshot, 'edge.state') === 'online'
            && data_get($snapshot, 'edge.public_url') === $expectedOrigin
            && ($ledger['canonical_origin'] ?? null) === $expectedOrigin
            && data_get($snapshot, 'upstream.state') === 'healthy'
            && data_get($snapshot, 'upstream.url') === $expectedUpstream
            && ($ledger['canonical_upstream'] ?? null) === $expectedUpstream;
    }

    /** @return list<string> */
    private function configurationReasons(?NgrokSettingsSnapshot $settings): array
    {
        if ($settings === null) {
            return ['remote_settings_unavailable'];
        }

        $reasons = [];
        if (! $settings->remoteAccessEnabled) {
            $reasons[] = 'remote_access_disabled';
        }
        if ($settings->externalOrigin === null) {
            $reasons[] = 'external_origin_missing_or_invalid';
        }
        if ($settings->authtoken === null) {
            $reasons[] = 'ngrok_authtoken_missing';
        }
        if ($this->debugPolicy->blocksRemoteAccess()) {
            $reasons[] = 'app_debug_enabled';
        }
        if ($this->canonicalUpstream() === null) {
            $reasons[] = 'ngrok_upstream_invalid';
        }

        return $reasons;
    }

    private function canonicalUpstream(): ?string
    {
        try {
            return NgrokLocalUpstream::fromString((string) config('ngrok-server.upstream_url'))->value();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed>|null $snapshot @return list<string> */
    private function snapshotReasons(?array $snapshot): array
    {
        $reasons = data_get($snapshot, 'readiness.reasons', []);
        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason) && $reason !== ''));
    }

    /** @return list<string> */
    private function summaryReasons(string $summary): array
    {
        return match ($summary) {
            'supervisor_unavailable' => ['supervisor_unavailable'],
            'reconciling' => ['runtime_state_reconciling'],
            'recovery_required' => ['orphan_unverified'],
            default => [],
        };
    }

    /** @param array<string, mixed>|null $snapshot */
    private function supervisorFresh(?array $snapshot): bool
    {
        $heartbeat = data_get($snapshot, 'supervisor.heartbeat_at');
        if (! is_string($heartbeat)) {
            return false;
        }
        $timestamp = strtotime($heartbeat);
        if ($timestamp === false) {
            return false;
        }
        $staleAfter = max(1000, (int) config('ngrok-server.heartbeat_stale_after_ms', 6000));

        return (microtime(true) - $timestamp) * 1000 <= $staleAfter;
    }

    /** @param array<string, mixed>|null $snapshot */
    private function generationMatches(?array $snapshot, NgrokSettingsSnapshot $settings): bool
    {
        if ($snapshot === null) {
            return false;
        }
        $generation = data_get($snapshot, 'desired.generation');

        return is_string($generation)
            && hash_equals($this->settingsGeneration->fingerprint($settings), $generation);
    }

    private function safeSettings(): ?NgrokSettingsSnapshot
    {
        try {
            return $this->settings->capture();
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeCurrentVersion(): ?NgrokSettingsVersion
    {
        try {
            return $this->settings->currentVersion();
        } catch (\Throwable) {
            return null;
        }
    }

    private function sameVersion(?NgrokSettingsVersion $left, ?NgrokSettingsVersion $right): bool
    {
        return $left !== null && $right !== null && $left->equals($right);
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function sameLifecycleState(array $left, array $right): bool
    {
        foreach ([
            'state',
            'state_sequence',
            'spawn_attempt_id',
            'endpoint_instance_id',
            'supervisor_instance_id',
            'settings_version',
            'boot_epoch_id',
            'agent_api_address',
            'canonical_origin',
            'canonical_upstream',
            'process_ownership_type',
            'process_ownership_id',
            'pid',
        ] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function base(?NgrokSettingsSnapshot $settings): array
    {
        return [
            'summary' => 'unknown',
            'direct' => $this->directStatus->project($settings),
            'ngrok_policy' => [
                'web_enabled' => $settings?->ngrokWebEnabled ?? false,
                'api_enabled' => $settings?->ngrokApiEnabled ?? false,
            ],
            'desired' => [
                'enabled' => $settings?->desiredEnabled ?? false,
                'version' => $this->versionArray($settings?->version),
            ],
            'configuration' => [
                'remote_access_enabled' => $settings?->remoteAccessEnabled ?? false,
                'approved_origin' => $settings?->externalOrigin?->value(),
                'upstream_url' => (string) config('ngrok-server.upstream_url'),
                'token' => [
                    'configured' => $settings?->authtoken !== null,
                    'source' => $settings?->authtokenSource ?? 'none',
                ],
            ],
            'manifest' => ['state' => 'unknown', 'installation_runtime_id' => null],
            'ledger' => ['state' => 'unknown', 'state_sequence' => null, 'spawn_attempt_id' => null],
            'token' => [
                'configured' => $settings?->authtoken !== null,
                'source' => $settings?->authtokenSource ?? 'none',
            ],
            'safety' => [
                'manifest_state' => 'unknown',
                'ledger_state' => 'unknown',
                'recovery_required' => false,
                'generation_matches' => false,
            ],
            'supervisor' => ['state' => 'unavailable', 'heartbeat_at' => null],
            'child' => ['state' => 'unknown', 'pid' => null],
            'edge' => ['state' => 'unknown', 'public_url' => null],
            'tunnel' => ['state' => 'unknown', 'public_url' => null],
            'upstream' => ['state' => 'unknown', 'url' => (string) config('ngrok-server.upstream_url')],
            'readiness' => ['state' => 'unknown', 'reasons' => [], 'generation_matches' => false],
            'diagnostics' => [
                'ngrok_version' => null,
                'agent_api_port' => null,
                'observed_at' => null,
                'debug_mode' => $this->debugPolicy->debugEnabled(),
                'force_access_in_debug_mode' => $this->debugPolicy->overrideEnabled(),
                'debug_override_active' => $this->debugPolicy->overrideActive(),
            ],
            'actions' => [
                'can_start' => false,
                'can_stop' => $settings?->desiredEnabled === true,
            ],
        ];
    }

    /** @param array<string, mixed> $base @param array<string, mixed>|null $manifest @return array<string, mixed> */
    private function terminal(array $base, string $summary, string $reason, ?array $manifest = null): array
    {
        $base['summary'] = $summary;
        $manifestState = is_string($manifest['state'] ?? null)
            ? $manifest['state']
            : ($summary === 'bootstrap_required' ? 'absent' : 'unknown');
        $ledgerState = $summary === 'bootstrap_required' ? 'absent' : 'unknown';
        $base['manifest'] = [
            'state' => $manifestState,
            'installation_runtime_id' => is_string($manifest['installation_runtime_id'] ?? null)
                ? $manifest['installation_runtime_id']
                : null,
        ];
        $base['ledger'] = ['state' => $ledgerState, 'state_sequence' => null, 'spawn_attempt_id' => null];
        $base['safety'] = [
            'manifest_state' => $manifestState,
            'ledger_state' => $ledgerState,
            'recovery_required' => $summary === 'recovery_required',
            'generation_matches' => false,
        ];
        $base['readiness'] = ['state' => 'blocked', 'reasons' => [$reason], 'generation_matches' => false];

        return $base;
    }

    /** @return array{incarnation: string, revision: int}|null */
    private function versionArray(?NgrokSettingsVersion $version): ?array
    {
        return $version?->toArray();
    }
}
