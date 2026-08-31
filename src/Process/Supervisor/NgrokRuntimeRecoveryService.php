<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnershipResolver;
use SimoneBianco\LaravelNgrokServer\Process\Windows\WindowsJobObjectInspector;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;

final readonly class NgrokRuntimeRecoveryService
{
    private const int CURL_COULD_NOT_CONNECT = 7;

    private const int CURL_OPERATION_TIMEDOUT = 28;

    public function __construct(
        private NgrokRuntimeLock $lock,
        private NgrokRuntimeManifestStore $manifests,
        private NgrokLifecycleLedgerStore $ledgers,
        private NgrokProcessOwnershipResolver $ownership,
        private WindowsJobObjectInspector $windowsJobs,
        private NgrokRuntimePaths $paths,
        private NgrokRuntimeLifecycleFence $fence,
        private NgrokRuntimeSettingsSnapshotProvider $settings,
        private NgrokSupervisorShutdownSignal $shutdownSignal,
        private AgentApiReachabilityProbe $agentApi,
        private NgrokRuntimeSpawnGate $gate,
    ) {}

    /** @return 'already_clean'|'recovered' */
    public function recover(): string
    {
        $lock = $this->lock->acquire();
        if ($lock === null) {
            throw new RuntimeException('Another ngrok supervisor owns this installation (LOCK_HELD).');
        }

        try {
            $this->fence->synchronized(function () use ($lock): void {
                $this->assertDesiredDisabled();
                $this->gate->reclaim($lock);
            });
            $manifest = $this->manifests->read() ?? throw new RuntimeException('Ngrok runtime bootstrap is required.');
            $this->manifests->assertInitialized($manifest);
            $ledger = $this->ledgers->read() ?? throw new RuntimeException('Ngrok lifecycle ledger is missing.');
            $this->ledgers->assertLinked($manifest, $ledger);
            if (($ledger['state'] ?? null) === 'clean') {
                $this->fence->synchronized(function () use ($lock): void {
                    $this->assertDesiredDisabled();
                    $this->gate->enable($lock);
                });

                return 'already_clean';
            }

            $attempt = $ledger['spawn_attempt_id'] ?? null;
            if (! is_string($attempt)) {
                throw new RuntimeException('The ngrok recovery attempt identity is unavailable.');
            }
            $processOwnership = new NgrokProcessOwnership(
                type: is_string($ledger['process_ownership_type'] ?? null) ? $ledger['process_ownership_type'] : '',
                id: is_string($ledger['process_ownership_id'] ?? null) ? $ledger['process_ownership_id'] : null,
            );
            $this->ownership->assertMatchesAttempt($processOwnership, $attempt);
            if (! $processOwnership->isHandleBound() || PHP_OS_FAMILY !== 'Windows') {
                throw new RuntimeException('Automatic ngrok recovery requires handle-bound Windows Job Object ownership.');
            }
            if ($this->windowsJobs->exists($processOwnership)) {
                throw new RuntimeException('The owned ngrok Windows Job Object still exists; recovery is not safe.');
            }

            $agentApiAddress = $ledger['agent_api_address'] ?? null;
            if (! is_string($agentApiAddress) || ! $this->agentApi->unavailable($agentApiAddress)) {
                throw new RuntimeException('The previous ngrok Agent API is still reachable; recovery is not safe.');
            }

            $relativeConfig = $ledger['runtime_config_path'] ?? null;
            $this->fence->synchronized(function () use ($lock, $manifest, $attempt, $processOwnership): void {
                $this->assertDesiredDisabled();

                $currentManifest = $this->manifests->read() ?? throw new RuntimeException('Ngrok runtime manifest disappeared during recovery.');
                $this->manifests->assertInitialized($currentManifest);
                if (($currentManifest['installation_runtime_id'] ?? null) !== ($manifest['installation_runtime_id'] ?? null)) {
                    throw new RuntimeException('Ngrok runtime identity changed during recovery.');
                }
                $currentLedger = $this->ledgers->read() ?? throw new RuntimeException('Ngrok lifecycle ledger disappeared during recovery.');
                $this->ledgers->assertLinked($currentManifest, $currentLedger);
                if (($currentLedger['spawn_attempt_id'] ?? null) !== $attempt || ($currentLedger['state'] ?? null) === 'clean') {
                    throw new RuntimeException('Ngrok lifecycle state changed during recovery.');
                }
                if ($this->windowsJobs->exists($processOwnership)) {
                    throw new RuntimeException('The owned ngrok Windows Job Object reappeared; recovery is not safe.');
                }

                $this->ledgers->markClean($currentManifest, $attempt);
                $this->gate->enable($lock);
            });
            if (is_string($relativeConfig) && preg_match('/\Aconfigs\/[a-f0-9]{32}\.yml\z/D', $relativeConfig) === 1) {
                @unlink($this->paths->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeConfig));
            }
            $this->shutdownSignal->reset();

            return 'recovered';
        } finally {
            $lock->release();
        }
    }

    private function assertDesiredDisabled(): void
    {
        if ($this->settings->capture(1)->desiredEnabled) {
            throw new RuntimeException('Disable the desired ngrok state before recovery.');
        }
    }
}
