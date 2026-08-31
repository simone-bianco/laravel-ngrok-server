<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\State;

use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiObservation;
use SimoneBianco\LaravelNgrokServer\Process\Launch\OwnedNgrokProcess;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final readonly class NgrokReadyObservation
{
    public function __construct(
        public float $observedAt,
        public NgrokSettingsVersion $settingsVersion,
        public string $spawnAttemptId,
        public string $endpointInstanceId,
        public string $bootEpochId,
        public bool $identityHealthy,
        public NgrokAgentApiObservation $agentApi,
    ) {}

    public static function capture(
        NgrokSettingsSnapshot $settings,
        OwnedNgrokProcess $owned,
        bool $identityHealthy,
        NgrokAgentApiObservation $agentApi,
    ): self {
        return new self(
            observedAt: microtime(true),
            settingsVersion: $settings->version,
            spawnAttemptId: $owned->launch->spawnAttemptId,
            endpointInstanceId: $owned->launch->endpointInstanceId,
            bootEpochId: $owned->launch->bootEpochId,
            identityHealthy: $identityHealthy,
            agentApi: $agentApi,
        );
    }

    public function isFresh(?float $now = null): bool
    {
        $maximumAge = max(100, (int) config('ngrok-server.ready_observation_max_age_ms', 2000));

        return (($now ?? microtime(true)) - $this->observedAt) * 1000 <= $maximumAge;
    }

    public function matches(OwnedNgrokProcess $owned): bool
    {
        return $this->spawnAttemptId === $owned->launch->spawnAttemptId
            && $this->endpointInstanceId === $owned->launch->endpointInstanceId
            && $this->bootEpochId === $owned->launch->bootEpochId
            && $this->settingsVersion->equals($owned->launch->settings->version);
    }
}
