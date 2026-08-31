<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSnapshotStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;

final readonly class NgrokRuntimeBootstrapper
{
    public function __construct(
        private NgrokRuntimePaths $paths,
        private NgrokRuntimeLock $lock,
        private NgrokRuntimeManifestStore $manifests,
        private NgrokLifecycleLedgerStore $ledgers,
        private NgrokRuntimeSnapshotStore $snapshots,
        private NgrokRuntimeSpawnGate $gate,
        private NgrokRuntimeLifecycleFence $fence,
        private NgrokRuntimeSettingsSnapshotProvider $settings,
    ) {}

    /** @return array<string, mixed> */
    public function initialize(): array
    {
        $this->paths->ensureRoot();
        $lock = $this->lock->acquire();
        if ($lock === null) {
            throw new RuntimeException('The ngrok runtime is already owned by another process.');
        }
        try {
            $this->fence->synchronized(function () use ($lock): void {
                if ($this->manifests->read() !== null || $this->ledgers->read() !== null) {
                    throw new RuntimeException('The ngrok runtime has already been initialized or requires recovery.');
                }
                if ($this->settings->capture(1)->desiredEnabled) {
                    throw new RuntimeException('Disable the ngrok desired state before initializing its runtime.');
                }
                $this->gate->disable($lock);
            });
            $this->assertNoPriorRuntimeArtifacts();

            return $this->fence->synchronized(function () use ($lock): array {
                if ($this->manifests->read() !== null || $this->ledgers->read() !== null) {
                    throw new RuntimeException('The ngrok runtime has already been initialized or requires recovery.');
                }
                $settings = $this->settings->capture(1);
                if ($settings->desiredEnabled) {
                    throw new RuntimeException('Disable the ngrok desired state before initializing its runtime.');
                }

                $manifest = $this->manifests->createInitializing();
                $ledger = $this->ledgers->createCleanExclusive($manifest);
                $manifest = $this->manifests->markInitialized($manifest);
                $this->ledgers->assertLinked($manifest, $ledger);
                $this->snapshots->publish([
                    'summary' => 'stopped',
                    'desired' => ['enabled' => false, 'version' => $settings->version->toArray()],
                    'manifest' => ['state' => 'initialized', 'installation_runtime_id' => $manifest['installation_runtime_id']],
                    'ledger' => ['state' => 'clean', 'state_sequence' => $ledger['state_sequence']],
                    'supervisor' => ['state' => 'unavailable', 'heartbeat_at' => null],
                    'child' => ['state' => 'absent', 'pid' => null],
                    'edge' => ['state' => 'offline', 'public_url' => null],
                    'upstream' => ['state' => 'unknown'],
                    'readiness' => ['state' => 'stopped', 'reasons' => []],
                ]);
                $this->gate->enable($lock);

                return ['manifest' => $manifest, 'ledger' => $ledger];
            });
        } finally {
            $lock->release();
        }
    }

    private function assertNoPriorRuntimeArtifacts(): void
    {
        $allowed = [basename($this->paths->lock()), basename($this->paths->spawnGate())];
        $entries = @scandir($this->paths->root());
        if (! is_array($entries)) {
            throw new RuntimeException('Unable to inspect the ngrok runtime root.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $allowed, true)) {
                continue;
            }
            throw new RuntimeException("Unknown ngrok runtime artifact [$entry] prevents fresh initialization.");
        }
    }
}
