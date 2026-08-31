<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\AgentApi;

use SimoneBianco\LaravelNgrokServer\Process\Launch\OwnedNgrokProcess;

final class NgrokAgentApiAvailabilityPolicy
{
    public function startupGraceExpired(OwnedNgrokProcess $owned, ?float $now = null): bool
    {
        $graceMs = max(0, (int) config('ngrok-server.agent_api_startup_grace_ms', 10_000));
        $elapsedMs = (($now ?? microtime(true)) - $owned->startedAt) * 1000;

        return $elapsedMs >= $graceMs;
    }
}
