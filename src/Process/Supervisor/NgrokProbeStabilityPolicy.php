<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

final class NgrokProbeStabilityPolicy
{
    public function transientFailureGraceExpired(float $unavailableSince, ?float $now = null): bool
    {
        $graceMs = max(0, (int) config('ngrok-server.runtime_probe_failure_grace_ms', 30_000));
        $elapsedMs = (($now ?? microtime(true)) - $unavailableSince) * 1000;

        return $elapsedMs >= $graceMs;
    }
}
