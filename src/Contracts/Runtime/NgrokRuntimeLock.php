<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

interface NgrokRuntimeLock
{
    public function acquire(): ?NgrokRuntimeLockHandle;
}
