<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;

interface NgrokRuntimeUpstreamProvider
{
    public function upstream(): NgrokLocalUpstream;

    public function hostHeader(): string;
}
