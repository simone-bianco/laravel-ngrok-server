<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiAvailabilityPolicy;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiClient;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiObservation;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparation;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparer;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokProcessLauncher;
use SimoneBianco\LaravelNgrokServer\Process\Launch\OwnedNgrokProcess;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\NgrokRuntimeIdentityProbe;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\RuntimeIdentityObservation;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokReadyObservation;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSnapshotStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokSettingsGeneration;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class NgrokSupervisor
{
    public const int EXIT_LOCK_HELD = 20;

    public const int EXIT_BOOTSTRAP_REQUIRED = 21;

    public const int EXIT_RECOVERY_REQUIRED = 22;

    private ?OwnedNgrokProcess $owned = null;

    private readonly string $supervisorInstanceId;

    private readonly string $bootEpochId;

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    private ?float $identityUnavailableSince = null;

    private ?float $identityVerifiedAt = null;

    private ?float $identityObservedAt = null;

    private ?string $identityState = null;

    private float $nextIdentityProbeAt = 0.0;

    private ?float $agentApiUnavailableSince = null;

    private ?float $agentApiCheckedAt = null;

    private ?string $agentApiState = null;

    public function __construct(
        private readonly NgrokRuntimeLock $lock,
        private readonly NgrokRuntimeManifestStore $manifests,
        private readonly NgrokLifecycleLedgerStore $ledgers,
        private readonly NgrokRuntimeSnapshotStore $snapshots,
        private readonly NgrokRuntimeLifecycleFence $fence,
        private readonly NgrokRuntimeSettingsSnapshotProvider $settings,
        private readonly NgrokRuntimeSpawnGate $gate,
        private readonly NgrokSettingsGeneration $settingsGeneration,
        private readonly NgrokLaunchPreparer $preparer,
        private readonly NgrokProcessLauncher $processes,
        private readonly NgrokAgentApiClient $agentApi,
        private readonly NgrokAgentApiAvailabilityPolicy $agentApiAvailability,
        private readonly NgrokProbeStabilityPolicy $probeStability,
        private readonly NgrokRuntimeIdentityProbe $identity,
        private readonly NgrokRuntimeRemoteAccessPolicy $debugPolicy,
        private readonly NgrokSupervisorShutdownSignal $shutdownSignal,
    ) {
        $this->supervisorInstanceId = bin2hex(random_bytes(16));
        $this->bootEpochId = bin2hex(random_bytes(16));
    }

    /** @param callable(): bool $shouldStop */
    public function run(callable $shouldStop, ?NgrokSettingsVersion $expectedGeneration = null): int
    {
        $shutdownRequest = null;
        $lock = $this->lock->acquire();
        if ($lock === null) {
            return self::EXIT_LOCK_HELD;
        }
        try {
            $bootstrapState = $this->loadRuntimeSafetyState();
            if ($bootstrapState !== 0) {
                return $bootstrapState;
            }
            try {
                $generationActive = $this->fence->synchronized(function () use ($expectedGeneration): bool {
                    $this->gate->assertEnabled();
                    if ($expectedGeneration === null) {
                        return true;
                    }
                    $current = $this->settings->capture(1);

                    return $current->desiredEnabled && $current->version->equals($expectedGeneration);
                });
            } catch (\Throwable) {
                return $expectedGeneration === null ? self::EXIT_RECOVERY_REQUIRED : 0;
            }
            if (! $generationActive) {
                return 0;
            }
            while (! $this->shouldStop($shouldStop, $shutdownRequest)) {
                try {
                    $this->reconcile();
                } catch (\Throwable $exception) {
                    $this->publishSafely('error', 'error', [$this->sanitizeError($exception)], settings: $this->safeSettings());
                }
                $this->waitInterruptibly($shouldStop, $shutdownRequest);
            }
            $this->stopOwned('supervisor_shutdown', $this->safeSettings());
            if (is_array($shutdownRequest) && is_string($shutdownRequest['request_id'] ?? null)) {
                $this->acknowledgeShutdown($shutdownRequest['request_id']);
            }

            return 0;
        } finally {
            $lock->release();
        }
    }

    private function loadRuntimeSafetyState(): int
    {
        try {
            $manifest = $this->manifests->read();
        } catch (\Throwable $exception) {
            $this->publish('recovery_required', 'blocked', ['manifest_corrupt']);

            return self::EXIT_RECOVERY_REQUIRED;
        }
        if ($manifest === null) {
            try {
                $ledger = $this->ledgers->read();
            } catch (\Throwable) {
                $ledger = ['state' => 'unknown'];
            }

            // Do not create observation artifacts before the create-once
            // bootstrap. Status derives bootstrap_required from absent safety files.
            return $ledger === null ? self::EXIT_BOOTSTRAP_REQUIRED : self::EXIT_RECOVERY_REQUIRED;
        }
        try {
            $this->manifests->assertInitialized($manifest);
            $ledger = $this->ledgers->read() ?? throw new RuntimeException('Ngrok lifecycle ledger is missing.');
            $this->ledgers->assertLinked($manifest, $ledger);
            if (($ledger['state'] ?? null) !== 'clean') {
                $this->publish('recovery_required', 'blocked', ['orphan_unverified']);

                return self::EXIT_RECOVERY_REQUIRED;
            }
        } catch (\Throwable $exception) {
            $reason = ($manifest['state'] ?? null) === 'initializing' ? 'bootstrap_incomplete' : 'recovery_required';
            $this->publish($reason, 'blocked', [$reason]);

            return self::EXIT_RECOVERY_REQUIRED;
        }
        $this->manifest = $manifest;

        return 0;
    }

    private function reconcile(): void
    {
        $settings = $this->captureSettingsOrStopOwned();
        if ($settings === null) {
            return;
        }
        if ($this->owned !== null && ! $this->owned->process->isRunning()) {
            $attempt = $this->owned->launch->spawnAttemptId;
            if (! $this->verifyOwnedAttemptQuiescent($this->owned)) {
                $this->ledgers->markUncertain($this->requireManifest(), $attempt);
                throw new RuntimeException('Ngrok exited but exact endpoint quiescence could not be verified.');
            }
            $this->ledgers->markClean($this->requireManifest(), $attempt);
            $this->removeRuntimeConfig($this->owned);
            $this->owned = null;
            $this->resetProbeFailures();
        }

        if ($this->owned !== null) {
            if (! $settings->desiredEnabled
                || ! $settings->remoteAccessEnabled
                || $settings->externalOrigin === null
                || $settings->authtoken === null
                || $this->debugPolicy->blocksRemoteAccess()
                || ! $this->owned->launch->settings->remoteValuesEqual($settings)) {
                $this->stopOwned('hard_configuration_blocker', $settings);

                return;
            }
            if (! $this->owned->launch->settings->version->equals($settings->version)) {
                $this->owned = $this->owned->withSettings($settings);
            }
            $now = microtime(true);

            // A verified identity belongs to the exact owned attempt. Once the
            // upstream has proven its identity for this child, a slow or
            // temporarily unreachable upstream cannot revoke ownership: keep
            // the healthy ngrok process and report degradation. A child that
            // has never been verified remains fail-closed and still uses the
            // bounded grace below.
            if ($this->identityUnavailableSince !== null
                && $this->identityVerifiedAt === null
                && $this->probeStability->transientFailureGraceExpired($this->identityUnavailableSince, $now)) {
                $this->stopOwned('upstream_identity_unavailable', $settings);

                return;
            }

            if ($this->identityProbeIsDue($now)) {
                $owned = $this->owned;
                $identity = $this->identity->observe(
                    $owned->launch->upstream,
                    $owned->launch->upstreamHostHeader,
                );
                if ($this->owned !== $owned) {
                    // A delayed probe result must never be applied to a newer attempt.
                    return;
                }
                $this->recordIdentityObservation($identity->state);
                if ($identity->state === RuntimeIdentityObservation::MISMATCH) {
                    $this->stopOwned('upstream_identity_mismatch', $settings);

                    return;
                }
                if ($identity->state === RuntimeIdentityObservation::UNAVAILABLE) {
                    $this->identityUnavailableSince ??= $now;
                    $this->scheduleIdentityProbeRetry($now);
                    $this->publish('degraded', 'degraded', ['upstream_identity_temporarily_unavailable'], $settings, $this->owned);

                    return;
                }
                $this->identityVerifiedAt = microtime(true);
                $this->identityUnavailableSince = null;
                $this->clearIdentityProbeRetry();
            } else {
                // The last observation was transport unavailability. Reuse it
                // instead of adding another request to an already loaded pool.
                $this->publish('degraded', 'degraded', ['upstream_identity_temporarily_unavailable'], $settings, $this->owned);

                return;
            }

            $this->agentApiState = null;
            $this->agentApiCheckedAt = null;
            $agentApi = $this->agentApi->observe($this->owned->launch);
            $this->agentApiState = $agentApi->state;
            $this->agentApiCheckedAt = microtime(true);
            if ($agentApi->state === NgrokAgentApiObservation::MISMATCH) {
                $this->stopOwned('agent_api_endpoint_mismatch', $settings);

                return;
            }
            if ($agentApi->state === NgrokAgentApiObservation::UNAVAILABLE) {
                if (! $this->agentApiAvailability->startupGraceExpired($this->owned)) {
                    $this->agentApiUnavailableSince = null;
                    $this->publish('starting', 'starting', ['edge_not_ready'], $settings, $this->owned);

                    return;
                }
                $this->agentApiUnavailableSince ??= microtime(true);
                if ($this->probeStability->transientFailureGraceExpired($this->agentApiUnavailableSince)) {
                    $this->stopOwned('agent_api_unavailable', $settings);

                    return;
                }
                $this->publish('degraded', 'degraded', ['agent_api_temporarily_unavailable'], $settings, $this->owned);

                return;
            }
            $this->agentApiUnavailableSince = null;
            $observation = NgrokReadyObservation::capture($settings, $this->owned, true, $agentApi);
            $this->publishReadyFenced($settings, $this->owned, $observation);

            return;
        }

        $ledger = $this->ledgers->requireState($this->requireManifest(), 'clean');
        if (! $settings->desiredEnabled) {
            $this->identityUnavailableSince = null;
            $this->clearIdentityProbeRetry();
            $this->publish('stopped', 'stopped', [], $settings, ledger: $ledger);

            return;
        }
        if (! $settings->remoteAccessEnabled || $settings->externalOrigin === null || $settings->authtoken === null || $this->debugPolicy->blocksRemoteAccess()) {
            $this->publish('blocked', 'blocked', $this->configurationBlockers($settings), $settings, ledger: $ledger);

            return;
        }

        // No owned child exists here, so a successful probe authorizes a spawn
        // but never counts as identity evidence for the child that will start.
        $this->identityVerifiedAt = null;
        $upstream = $this->preparer->resolveUpstream();
        $upstreamHostHeader = $this->preparer->resolveUpstreamHostHeader();
        $now = microtime(true);
        if (! $this->identityProbeIsDue($now)) {
            $this->publish(
                'blocked',
                'blocked',
                [$this->identityState === RuntimeIdentityObservation::MISMATCH ? 'upstream_identity_mismatch' : 'upstream_identity_unavailable'],
                $settings,
                ledger: $ledger,
            );

            return;
        }
        $identity = $this->identity->observe($upstream, $upstreamHostHeader);
        $this->recordIdentityObservation($identity->state);
        if ($identity->state !== RuntimeIdentityObservation::READY) {
            if ($identity->state === RuntimeIdentityObservation::UNAVAILABLE) {
                $this->identityUnavailableSince ??= $now;
                $this->scheduleIdentityProbeRetry($now);
            } else {
                $this->identityUnavailableSince = null;
                $this->clearIdentityProbeRetry();
            }
            $this->publish(
                'blocked',
                'blocked',
                [$identity->state === RuntimeIdentityObservation::MISMATCH ? 'upstream_identity_mismatch' : 'upstream_identity_unavailable'],
                $settings,
                ledger: $ledger,
            );

            return;
        }
        $this->identityUnavailableSince = null;
        $this->clearIdentityProbeRetry();
        $preparation = $this->preparer->prepare($settings, $this->supervisorInstanceId, $this->bootEpochId);
        $this->owned = $this->spawnFenced($preparation);
        $this->resetProbeFailures();
        try {
            $this->publish('starting', 'starting', [], $settings, $this->owned);
        } catch (\Throwable $exception) {
            $this->stopOwned('snapshot_publish_failed', $settings);
            throw $exception;
        }
    }

    private function spawnFenced(NgrokLaunchPreparation $prepared): OwnedNgrokProcess
    {
        $started = null;
        try {
            return $this->fence->synchronized(function () use ($prepared, &$started): OwnedNgrokProcess {
                $this->gate->assertEnabled();
                $current = $this->settings->capture(1);
                if (! $current->version->equals($prepared->settings->version) || ! $current->remoteValuesEqual($prepared->settings)) {
                    throw new RuntimeException('Remote ngrok settings changed before spawn linearization.');
                }
                $manifest = $this->requireManifest();
                $this->ledgers->requireState($manifest, 'clean');
                $this->ledgers->arm($manifest, [
                    'spawn_attempt_id' => $prepared->spawnAttemptId,
                    'endpoint_instance_id' => $prepared->endpointInstanceId,
                    'supervisor_instance_id' => $prepared->supervisorInstanceId,
                    'settings_version' => $this->versionArray($prepared->settings->version),
                    'boot_epoch_id' => $prepared->bootEpochId,
                    'agent_api_address' => $prepared->agentApiAddress,
                    'canonical_origin' => $prepared->origin->value(),
                    'canonical_upstream' => $prepared->upstream->value(),
                    'runtime_config_path' => $prepared->configRelativePath,
                    'runtime_config_sha256' => $prepared->configSha256,
                    'process_ownership_type' => $prepared->ownership->type,
                    'process_ownership_id' => $prepared->ownership->id,
                ]);
                try {
                    $started = $this->processes->start($prepared);
                    $pid = $started->process->getPid();
                    if (! is_int($pid)) {
                        $this->ledgers->markUncertain($manifest, $prepared->spawnAttemptId);
                        throw new RuntimeException('Ngrok started without a verifiable process id.');
                    }
                    $this->ledgers->markActive($manifest, $prepared->spawnAttemptId, $pid);

                    return $started;
                } catch (\Throwable $exception) {
                    try {
                        $this->ledgers->markUncertain($manifest, $prepared->spawnAttemptId);
                    } catch (\Throwable) {
                    }
                    throw $exception;
                }
            });
        } catch (\Throwable $exception) {
            if ($started instanceof OwnedNgrokProcess
                && $this->processes->stop($started)
                && $this->verifyOwnedAttemptQuiescent($started)) {
                try {
                    $this->ledgers->markClean($this->requireManifest(), $prepared->spawnAttemptId);
                } catch (\Throwable) {
                }
            } else {
                try {
                    $this->ledgers->markUncertain($this->requireManifest(), $prepared->spawnAttemptId);
                } catch (\Throwable) {
                }
            }
            $this->removePreparationConfigWhenClean($prepared);
            throw $exception;
        }
    }

    private function publishReadyFenced(
        NgrokSettingsSnapshot $observed,
        OwnedNgrokProcess $owned,
        NgrokReadyObservation $observation,
    ): void {
        $this->fence->synchronized(function () use ($observed, $owned, $observation): void {
            $current = $this->settings->capture(1);
            if (! $observation->isFresh()
                || ! $observation->matches($owned)
                || ! $observation->identityHealthy
                || $observation->agentApi->state !== NgrokAgentApiObservation::READY
                || $observation->agentApi->endpoint === null
                || ! $current->version->equals($observed->version)
                || ! $current->version->equals($owned->launch->settings->version)
                || ! $current->remoteValuesEqual($observed)
                || ! $current->remoteValuesEqual($owned->launch->settings)
                || ! $current->desiredEnabled
                || ! $current->remoteAccessEnabled
                || $this->debugPolicy->blocksRemoteAccess()) {
                throw new RuntimeException('Remote ngrok settings or external observations changed before Ready publication.');
            }
            if (! $owned->process->isRunning()) {
                throw new RuntimeException('Ngrok exited before Ready publication.');
            }
            $this->ledgers->requireState($this->requireManifest(), 'child_active');
            $this->publish('ready', 'ready', [], $current, $owned);
        });
    }

    private function stopOwned(string $reason, ?NgrokSettingsSnapshot $settings = null): void
    {
        if ($this->owned === null) {
            return;
        }
        $owned = $this->owned;
        $attempt = $owned->launch->spawnAttemptId;
        try {
            $this->ledgers->markStopping($this->requireManifest(), $attempt);
        } catch (\Throwable) {
        }
        $this->publishSafely('stopping', 'stopping', [$reason], $settings, $owned);
        if ($this->processes->stop($owned) && $this->verifyOwnedAttemptQuiescent($owned)) {
            $this->ledgers->markClean($this->requireManifest(), $attempt);
            $this->removeRuntimeConfig($owned);
            $this->owned = null;
            $this->resetProbeFailures();
            $this->publishSafely($settings?->desiredEnabled ? 'blocked' : 'stopped', $settings?->desiredEnabled ? 'blocked' : 'stopped', [$reason], $settings);

            return;
        }
        try {
            $this->ledgers->markUncertain($this->requireManifest(), $attempt);
        } catch (\Throwable) {
        }
        throw new RuntimeException('Unable to verify ngrok process-tree exit.');
    }

    private function resetProbeFailures(): void
    {
        $this->identityUnavailableSince = null;
        $this->identityVerifiedAt = null;
        $this->identityObservedAt = null;
        $this->identityState = null;
        $this->nextIdentityProbeAt = 0.0;
        $this->agentApiUnavailableSince = null;
        $this->agentApiCheckedAt = null;
        $this->agentApiState = null;
    }

    private function identityProbeIsDue(float $now): bool
    {
        return $this->nextIdentityProbeAt <= 0.0 || $now >= $this->nextIdentityProbeAt;
    }

    private function scheduleIdentityProbeRetry(float $now): void
    {
        $intervalMs = max(1000, min(10000, (int) config('ngrok-server.runtime_identity_probe_degraded_interval_ms', 3000)));
        $this->nextIdentityProbeAt = $now + ($intervalMs / 1000);
    }

    private function clearIdentityProbeRetry(): void
    {
        $this->nextIdentityProbeAt = 0.0;
    }

    private function recordIdentityObservation(string $state): void
    {
        $this->identityState = $state;
        $this->identityObservedAt = microtime(true);
    }

    private function isoTimestamp(?float $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate(DATE_ATOM, (int) $timestamp);
    }

    /** @param list<string> $reasons @param array<string, mixed>|null $ledger */
    private function publish(string $summary, string $readiness, array $reasons, ?NgrokSettingsSnapshot $settings = null, ?OwnedNgrokProcess $owned = null, ?array $ledger = null): void
    {
        $settings ??= $this->safeSettings();
        $manifest = $this->manifest;
        if ($ledger === null && $manifest !== null) {
            try {
                $ledger = $this->ledgers->read();
            } catch (\Throwable) {
                $ledger = null;
            }
        }
        $running = $owned?->process->isRunning() === true;
        $agentApiChecked = $owned !== null && $this->agentApiState !== null;
        $agentApiState = $agentApiChecked ? $this->agentApiState : 'unknown';
        $edgeState = $summary === 'ready'
            ? 'online'
            : ($running
                ? ($agentApiChecked ? 'connecting' : ($summary === 'starting' ? 'starting' : 'unknown'))
                : ($summary === 'starting' ? 'starting' : 'offline'));
        $upstreamState = in_array('upstream_identity_mismatch', $reasons, true)
            ? 'identity_mismatch'
            : ($summary === 'ready'
                ? 'healthy'
                : (in_array('upstream_identity_temporarily_unavailable', $reasons, true)
                    ? 'temporarily_unavailable'
                    : (in_array('upstream_identity_unavailable', $reasons, true) ? 'unavailable' : 'unknown')));
        $identityVerified = $owned !== null && $this->identityVerifiedAt !== null;
        $this->snapshots->publish([
            'summary' => $summary,
            'desired' => [
                'enabled' => $settings?->desiredEnabled ?? false,
                'version' => $settings ? $this->versionArray($settings->version) : null,
                'generation' => $settings ? $this->settingsGeneration->fingerprint($settings) : null,
            ],
            'manifest' => ['state' => $manifest['state'] ?? ($summary === 'bootstrap_required' ? 'absent' : 'unknown'), 'installation_runtime_id' => $manifest['installation_runtime_id'] ?? null],
            'ledger' => ['state' => $ledger['state'] ?? 'unknown', 'state_sequence' => $ledger['state_sequence'] ?? null, 'spawn_attempt_id' => $ledger['spawn_attempt_id'] ?? null],
            'supervisor' => ['state' => 'online', 'instance_id' => $this->supervisorInstanceId, 'boot_epoch_id' => $this->bootEpochId, 'heartbeat_at' => gmdate(DATE_ATOM)],
            'child' => ['state' => $running ? 'running' : ($summary === 'starting' ? 'starting' : 'absent'), 'pid' => $running ? $owned?->process->getPid() : null, 'endpoint_instance_id' => $owned?->launch->endpointInstanceId],
            'agent_api' => [
                'state' => $agentApiState,
                'checked' => $agentApiChecked,
                'checked_at' => $this->isoTimestamp($this->agentApiCheckedAt),
                'address' => $owned?->launch->agentApiAddress,
            ],
            'edge' => ['state' => $edgeState, 'public_url' => $summary === 'ready' ? $owned?->launch->origin->value() : null],
            'upstream' => ['state' => $upstreamState, 'url' => $owned?->launch->upstream->value()],
            'identity' => [
                'state' => $this->identityState ?? 'unknown',
                'verified' => $identityVerified,
                'verified_at' => $this->isoTimestamp($this->identityVerifiedAt),
                'observed_at' => $this->isoTimestamp($this->identityObservedAt),
                'unavailable_since' => $this->isoTimestamp($this->identityUnavailableSince),
                'unavailable_ms' => $this->identityUnavailableSince === null
                    ? null
                    : max(0, (int) round((microtime(true) - $this->identityUnavailableSince) * 1000)),
            ],
            'readiness' => ['state' => $readiness, 'reasons' => array_values(array_unique($reasons))],
            'diagnostics' => [
                'ngrok_version' => $owned?->launch->binary->version,
                'agent_api_port' => $owned?->launch->agentApiPort,
                'identity_probe_timeout_ms' => max(1000, min(30000, (int) config('ngrok-server.runtime_identity_probe_timeout_ms', 5000))),
                'identity_probe_degraded_interval_ms' => max(1000, min(10000, (int) config('ngrok-server.runtime_identity_probe_degraded_interval_ms', 3000))),
                'debug_mode' => $this->debugPolicy->debugEnabled(),
                'force_access_in_debug_mode' => $this->debugPolicy->overrideEnabled(),
                'debug_override_active' => $this->debugPolicy->overrideActive(),
            ],
        ]);
    }

    /** @param list<string> $reasons @param array<string, mixed>|null $ledger */
    private function publishSafely(
        string $summary,
        string $readiness,
        array $reasons,
        ?NgrokSettingsSnapshot $settings = null,
        ?OwnedNgrokProcess $owned = null,
        ?array $ledger = null,
    ): void {
        try {
            $this->publish($summary, $readiness, $reasons, $settings, $owned, $ledger);
        } catch (\Throwable) {
            // Observation state is never allowed to block lifecycle safety.
        }
    }

    /** @return list<string> */
    private function configurationBlockers(NgrokSettingsSnapshot $settings): array
    {
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

        return $reasons;
    }

    private function captureSettingsOrStopOwned(): ?NgrokSettingsSnapshot
    {
        try {
            return $this->settings->capture();
        } catch (\Throwable) {
            $lastKnown = $this->owned?->launch->settings;
            if ($this->owned !== null) {
                try {
                    $this->stopOwned('remote_settings_unavailable', $lastKnown);
                } catch (\Throwable) {
                    $this->publishSafely(
                        'recovery_required',
                        'blocked',
                        ['remote_settings_unavailable', 'orphan_unverified'],
                        $lastKnown,
                        $this->owned,
                    );

                    return null;
                }
            }
            $this->publishSafely('blocked', 'blocked', ['remote_settings_unavailable'], $lastKnown);

            return null;
        }
    }

    /** @param callable(): bool $shouldStop @param array<string, mixed>|null $shutdownRequest */
    private function shouldStop(callable $shouldStop, ?array &$shutdownRequest): bool
    {
        $settings = $this->safeSettings();
        $shutdownRequest = $this->shutdownSignal->pendingFor($settings?->version);

        return $shutdownRequest !== null || $shouldStop();
    }

    /** @param callable(): bool $shouldStop @param array<string, mixed>|null $shutdownRequest */
    private function waitInterruptibly(callable $shouldStop, ?array &$shutdownRequest): void
    {
        $milliseconds = max(100, (int) config('ngrok-server.settings_reconcile_interval_ms', 1000));
        $ticks = max(1, (int) ceil($milliseconds / 100));
        for ($tick = 0; $tick < $ticks && ! $this->shouldStop($shouldStop, $shutdownRequest); $tick++) {
            usleep(100_000);
        }
    }

    private function acknowledgeShutdown(string $requestId): void
    {
        $this->fence->synchronized(function () use ($requestId): void {
            $request = $this->shutdownSignal->pendingFor($this->settings->capture(1)->version);
            if (($request['request_id'] ?? null) === $requestId) {
                $this->shutdownSignal->acknowledge($requestId);
            }
        });
    }

    private function safeSettings(): ?NgrokSettingsSnapshot
    {
        try {
            return $this->settings->capture(1);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{incarnation: string, revision: int} */
    private function versionArray(NgrokSettingsVersion $version): array
    {
        return $version->toArray();
    }

    private function verifyOwnedAttemptQuiescent(OwnedNgrokProcess $owned): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $agentApi = $this->agentApi->observe($owned->launch);
            if (! $owned->process->isRunning()
                && $agentApi->state === NgrokAgentApiObservation::UNAVAILABLE) {
                return true;
            }
            if ($agentApi->state === NgrokAgentApiObservation::MISMATCH) {
                return false;
            }
            usleep(100_000);
        }

        return false;
    }

    private function removeRuntimeConfig(OwnedNgrokProcess $owned): void
    {
        $this->removePreparationConfig($owned->launch);
    }

    private function removePreparationConfig(NgrokLaunchPreparation $preparation): void
    {
        @unlink($preparation->configPath);
    }

    private function removePreparationConfigWhenClean(NgrokLaunchPreparation $preparation): void
    {
        try {
            $ledger = $this->ledgers->read();
            if (is_array($ledger) && ($ledger['state'] ?? null) === 'clean') {
                $this->removePreparationConfig($preparation);
            }
        } catch (\Throwable) {
            // Keep the config as recovery evidence when lifecycle safety is unknown.
        }
    }

    /** @return array<string, mixed> */
    private function requireManifest(): array
    {
        return $this->manifest ?? throw new RuntimeException('Ngrok runtime manifest is unavailable.');
    }

    private function sanitizeError(\Throwable $exception): string
    {
        $message = preg_replace('/\s+/', ' ', trim($exception->getMessage())) ?: 'ngrok_runtime_error';

        return mb_substr($message, 0, 240);
    }
}
