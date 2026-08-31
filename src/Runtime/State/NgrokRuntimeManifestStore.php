<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\State;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;

final readonly class NgrokRuntimeManifestStore
{
    public function __construct(private NgrokRuntimePaths $paths, private DurableRuntimeFile $files) {}

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        return $this->files->readJson($this->paths->manifest());
    }

    /** @return array<string, mixed> */
    public function createInitializing(): array
    {
        $now = gmdate(DATE_ATOM);

        return $this->files->createJsonExclusive($this->paths->manifest(), [
            'schema_version' => (int) config('ngrok-server.manifest_schema_version', 1),
            'runtime_root_format_version' => 1,
            'state' => 'initializing',
            'installation_runtime_id' => bin2hex(random_bytes(16)),
            'bootstrap_attempt_id' => bin2hex(random_bytes(16)),
            'runtime_root_identity' => $this->paths->identity(),
            'created_at' => $now,
            'initialized_at' => null,
            'ledger_file_name' => basename($this->paths->ledger()),
            'ledger_schema_version' => (int) config('ngrok-server.ledger_schema_version', 1),
            'initial_ledger_sequence' => 0,
        ]);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function markInitialized(array $manifest): array
    {
        $this->assertState($manifest, 'initializing');
        $next = $manifest;
        $next['state'] = 'initialized';
        $next['initialized_at'] = gmdate(DATE_ATOM);

        return $this->files->replaceJson($this->paths->manifest(), $next);
    }

    /** @param array<string, mixed> $manifest */
    public function assertInitialized(array $manifest): void
    {
        $this->assertState($manifest, 'initialized');
    }

    /** @param array<string, mixed> $manifest */
    private function assertState(array $manifest, string $state): void
    {
        $installationId = $manifest['installation_runtime_id'] ?? null;
        $bootstrapId = $manifest['bootstrap_attempt_id'] ?? null;
        $createdAt = $manifest['created_at'] ?? null;
        $initializedAt = $manifest['initialized_at'] ?? null;
        $initializedAtValid = $state === 'initializing'
            ? $initializedAt === null
            : is_string($initializedAt) && strtotime($initializedAt) !== false;

        if (($manifest['schema_version'] ?? null) !== (int) config('ngrok-server.manifest_schema_version', 1)
            || ($manifest['runtime_root_format_version'] ?? null) !== 1
            || ($manifest['state'] ?? null) !== $state
            || ! is_string($installationId)
            || preg_match('/\A[a-f0-9]{32}\z/D', $installationId) !== 1
            || ! is_string($bootstrapId)
            || preg_match('/\A[a-f0-9]{32}\z/D', $bootstrapId) !== 1
            || ($manifest['runtime_root_identity'] ?? null) !== $this->paths->identity()
            || ! is_string($createdAt)
            || strtotime($createdAt) === false
            || ! $initializedAtValid
            || ($manifest['ledger_file_name'] ?? null) !== basename($this->paths->ledger())
            || ($manifest['ledger_schema_version'] ?? null) !== (int) config('ngrok-server.ledger_schema_version', 1)
            || ($manifest['initial_ledger_sequence'] ?? null) !== 0) {
            throw new RuntimeException("Ngrok runtime manifest is not in expected state [$state].");
        }
    }
}
