<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

interface NgrokRuntimeRemoteAccessPolicy
{
    public function debugEnabled(): bool;

    public function overrideEnabled(): bool;

    public function blocksRemoteAccess(): bool;

    public function overrideActive(): bool;
}
