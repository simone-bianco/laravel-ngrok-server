<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;

final readonly class NgrokSupervisorLock implements NgrokRuntimeLock
{
    public function __construct(private NgrokRuntimePaths $paths) {}

    public function acquire(): ?NgrokSupervisorLockHandle
    {
        $this->paths->ensureRoot();
        $handle = @fopen($this->paths->lock(), 'c+b');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to open the ngrok supervisor lock.');
        }
        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['pid' => getmypid(), 'acquired_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR)."\n");
        fflush($handle);

        return new NgrokSupervisorLockHandle($handle, bin2hex(random_bytes(16)));
    }
}
