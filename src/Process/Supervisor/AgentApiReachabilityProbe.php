<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

interface AgentApiReachabilityProbe
{
    public function unavailable(string $address): bool;
}
