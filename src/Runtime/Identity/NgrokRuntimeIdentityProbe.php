<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Identity;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;

interface NgrokRuntimeIdentityProbe
{
    public function observe(NgrokLocalUpstream $upstream, string $hostHeader = ''): RuntimeIdentityObservation;

    public function healthy(NgrokLocalUpstream $upstream, string $hostHeader = ''): bool;
}
