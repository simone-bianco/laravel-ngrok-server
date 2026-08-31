<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use SimoneBianco\LaravelNgrokServer\Process\Binary\NgrokBinary;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

final readonly class NgrokLaunchPreparation
{
    /** @param array<string, string|false> $environment */
    public function __construct(
        public string $spawnAttemptId,
        public string $endpointInstanceId,
        public string $supervisorInstanceId,
        public string $bootEpochId,
        public NgrokProcessOwnership $ownership,
        public NgrokBinary $binary,
        public RemoteExternalOrigin $origin,
        public NgrokLocalUpstream $upstream,
        public int $agentApiPort,
        public string $agentApiAddress,
        public string $configPath,
        public string $configRelativePath,
        public string $configSha256,
        public array $environment,
        public NgrokSettingsSnapshot $settings,
        public string $upstreamHostHeader = '',
    ) {}

    public function withSettings(NgrokSettingsSnapshot $settings): self
    {
        return new self(
            spawnAttemptId: $this->spawnAttemptId,
            endpointInstanceId: $this->endpointInstanceId,
            supervisorInstanceId: $this->supervisorInstanceId,
            bootEpochId: $this->bootEpochId,
            ownership: $this->ownership,
            binary: $this->binary,
            origin: $this->origin,
            upstream: $this->upstream,
            agentApiPort: $this->agentApiPort,
            agentApiAddress: $this->agentApiAddress,
            configPath: $this->configPath,
            configRelativePath: $this->configRelativePath,
            configSha256: $this->configSha256,
            environment: $this->environment,
            settings: $settings,
            upstreamHostHeader: $this->upstreamHostHeader,
        );
    }
}
