<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

interface NgrokRuntimeLockHandle
{
    public function ownerClaim(): string;

    public function isHeld(): bool;

    public function release(): void;
}
