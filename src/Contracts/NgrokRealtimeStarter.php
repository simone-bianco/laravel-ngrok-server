<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts;

interface NgrokRealtimeStarter
{
    public function ensureRunning(): void;
}
