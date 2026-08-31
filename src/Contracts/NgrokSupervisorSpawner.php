<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

interface NgrokSupervisorSpawner
{
    public function spawn(NgrokSettingsVersion $generation): void;
}
