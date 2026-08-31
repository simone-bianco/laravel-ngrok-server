<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Ownership;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;

final readonly class NgrokProcessOwnershipResolver
{
    public function __construct(private NgrokRuntimePaths $paths) {}

    public function forAttempt(string $spawnAttemptId): NgrokProcessOwnership
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $spawnAttemptId) !== 1) {
            throw new RuntimeException('Invalid ngrok spawn attempt id for process ownership.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return new NgrokProcessOwnership(NgrokProcessOwnership::POSIX_PROCESS, null);
        }

        return new NgrokProcessOwnership(
            NgrokProcessOwnership::WINDOWS_JOB,
            'Local\\LocalGptNgrok-'.substr($this->paths->identity(), 0, 16).'-'.$spawnAttemptId,
        );
    }

    public function assertMatchesAttempt(NgrokProcessOwnership $ownership, string $spawnAttemptId): void
    {
        $expected = $this->forAttempt($spawnAttemptId);
        if ($ownership->type !== $expected->type || $ownership->id !== $expected->id) {
            throw new RuntimeException('Ngrok process ownership does not match the spawn attempt.');
        }
    }
}
