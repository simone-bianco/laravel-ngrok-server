<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\ValueObjects;

final readonly class NgrokDesiredStateMutation
{
    public function __construct(
        public NgrokSettingsVersion $version,
        public bool $changed,
    ) {}
}
