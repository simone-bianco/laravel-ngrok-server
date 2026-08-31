<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\State;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLockHandle;
use SimoneBianco\LaravelNgrokServer\Exceptions\NgrokRuntimeTransitionInProgressException;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;

final readonly class NgrokRuntimeSpawnGate
{
    public function __construct(
        private NgrokRuntimePaths $paths,
        private DurableRuntimeFile $files,
    ) {}

    public function disable(NgrokRuntimeLockHandle $lock): void
    {
        $this->replace('disabled', $this->claim($lock));
    }

    public function reclaim(NgrokRuntimeLockHandle $lock): void
    {
        $this->replace('disabled', $this->claim($lock));
    }

    public function enable(NgrokRuntimeLockHandle $lock): void
    {
        $gate = $this->read();
        if (($gate['state'] ?? null) !== 'disabled' || ($gate['owner_claim'] ?? null) !== $this->claim($lock)) {
            throw new RuntimeException('The ngrok runtime spawn gate is not owned by this lifecycle transition.');
        }

        $this->replace('enabled', null);
    }

    public function assertEnabled(): void
    {
        $gate = $this->read();
        if (($gate['state'] ?? null) === 'disabled') {
            throw new NgrokRuntimeTransitionInProgressException('The ngrok runtime transition is in progress. Complete recovery before starting ngrok.');
        }
        if (($gate['state'] ?? null) !== 'enabled' || ($gate['owner_claim'] ?? null) !== null) {
            throw new RuntimeException('The ngrok runtime spawn gate is invalid. Complete recovery before starting ngrok.');
        }
    }

    public function isEnabled(): bool
    {
        return ($this->read()['state'] ?? null) === 'enabled';
    }

    /** @return array{schema_version: int, state: 'enabled'|'disabled', owner_claim: string|null} */
    public function read(): array
    {
        $gate = $this->files->readJson($this->paths->spawnGate())
            ?? throw new RuntimeException('The ngrok runtime spawn gate is missing. Complete recovery before starting ngrok.');
        $state = $gate['state'] ?? null;
        $ownerClaim = $gate['owner_claim'] ?? null;
        if (($gate['schema_version'] ?? null) !== 1
            || ! in_array($state, ['enabled', 'disabled'], true)
            || ! ($ownerClaim === null || (is_string($ownerClaim) && preg_match('/\A[a-f0-9]{32}\z/D', $ownerClaim) === 1))
            || ($state === 'enabled' && $ownerClaim !== null)
            || ($state === 'disabled' && ! is_string($ownerClaim))) {
            throw new RuntimeException('The ngrok runtime spawn gate is invalid. Complete recovery before starting ngrok.');
        }

        return $gate;
    }

    private function claim(NgrokRuntimeLockHandle $lock): string
    {
        if (! $lock->isHeld()) {
            throw new RuntimeException('The ngrok runtime lifecycle lock is no longer held.');
        }

        return $lock->ownerClaim();
    }

    private function replace(string $state, ?string $ownerClaim): void
    {
        $this->paths->ensureRoot();
        $this->files->replaceJson($this->paths->spawnGate(), [
            'schema_version' => 1,
            'state' => $state,
            'owner_claim' => $ownerClaim,
        ]);
    }
}
