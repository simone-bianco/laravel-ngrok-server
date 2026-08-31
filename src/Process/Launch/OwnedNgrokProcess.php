<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;

final readonly class OwnedNgrokProcess
{
    public float $startedAt;

    public function __construct(
        public NgrokProcessHandle $process,
        public NgrokLaunchPreparation $launch,
        ?float $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    public function withSettings(NgrokSettingsSnapshot $settings): self
    {
        return new self($this->process, $this->launch->withSettings($settings), $this->startedAt);
    }
}
