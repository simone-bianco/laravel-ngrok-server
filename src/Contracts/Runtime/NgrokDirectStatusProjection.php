<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;

interface NgrokDirectStatusProjection
{
    /** @return array<string, mixed> */
    public function project(?NgrokSettingsSnapshot $settings): array;
}
