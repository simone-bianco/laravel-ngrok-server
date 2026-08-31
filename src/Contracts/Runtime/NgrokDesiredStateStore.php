<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokDesiredStateMutation;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

interface NgrokDesiredStateStore
{
    public function set(bool $enabled, NgrokSettingsVersion $expected, bool $requireSpawnGate = false): NgrokDesiredStateMutation;
}
