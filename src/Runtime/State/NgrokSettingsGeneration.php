<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\State;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;

final readonly class NgrokSettingsGeneration
{
    public function fingerprint(NgrokSettingsSnapshot $settings): string
    {
        return sprintf(
            'sv1:%s:%d',
            $settings->version->incarnation,
            $settings->version->revision,
        );
    }
}
