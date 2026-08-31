<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts;

interface NgrokRemoteAccessPolicy
{
    public function blocksRemoteAccess(): bool;
}
