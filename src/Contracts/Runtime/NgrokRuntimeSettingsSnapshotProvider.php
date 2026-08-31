<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

interface NgrokRuntimeSettingsSnapshotProvider
{
    public function capture(int $attempts = 3): NgrokSettingsSnapshot;

    public function currentVersion(): NgrokSettingsVersion;
}
