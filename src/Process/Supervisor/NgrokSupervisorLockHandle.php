<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLockHandle;

final class NgrokSupervisorLockHandle implements NgrokRuntimeLockHandle
{
    /** @param resource $handle */
    public function __construct(private $handle, private readonly string $claim) {}

    public function ownerClaim(): string
    {
        return $this->claim;
    }

    public function isHeld(): bool
    {
        return is_resource($this->handle);
    }

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
