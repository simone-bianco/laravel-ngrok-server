<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeUpstreamProvider;
use SimoneBianco\LaravelNgrokServer\Process\Binary\NgrokBinaryResolver;
use SimoneBianco\LaravelNgrokServer\Process\Environment\NgrokChildEnvironmentBuilder;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnershipResolver;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;

final readonly class NgrokLaunchPreparer
{
    public function __construct(
        private NgrokBinaryResolver $binary,
        private NgrokAgentApiPortAllocator $ports,
        private NgrokAgentConfigWriter $configs,
        private NgrokChildEnvironmentBuilder $environment,
        private NgrokProcessOwnershipResolver $ownership,
        private NgrokRemoteAccessPolicy $debugPolicy,
        private NgrokRuntimeUpstreamProvider $upstream,
    ) {}

    public function prepare(NgrokSettingsSnapshot $settings, string $supervisorInstanceId, string $bootEpochId, array $excludedPorts = []): NgrokLaunchPreparation
    {
        if (! $settings->remoteAccessEnabled || ! $settings->desiredEnabled || $settings->externalOrigin === null || $settings->authtoken === null) {
            throw new RuntimeException('Remote access, ngrok desired state, approved origin and app authtoken are required.');
        }
        if ($this->debugPolicy->blocksRemoteAccess()) {
            throw new RuntimeException('Ngrok cannot start while APP_DEBUG is enabled.');
        }
        $upstream = $this->resolveUpstream();
        $upstreamHostHeader = $this->resolveUpstreamHostHeader();
        $attempt = bin2hex(random_bytes(16));
        $endpoint = bin2hex(random_bytes(16));
        $port = $this->ports->allocate($excludedPorts);
        $binary = $this->binary->resolve();
        $config = $this->configs->write($attempt, $port, $endpoint, $supervisorInstanceId, $settings->externalOrigin, $upstream, $upstreamHostHeader);
        try {
            $this->binary->validateConfig($binary, $config['path']);
        } catch (\Throwable $exception) {
            @unlink($config['path']);
            throw $exception;
        }

        return new NgrokLaunchPreparation(
            spawnAttemptId: $attempt,
            endpointInstanceId: $endpoint,
            supervisorInstanceId: $supervisorInstanceId,
            bootEpochId: $bootEpochId,
            ownership: $this->ownership->forAttempt($attempt),
            binary: $binary,
            origin: $settings->externalOrigin,
            upstream: $upstream,
            agentApiPort: $port,
            agentApiAddress: "http://127.0.0.1:$port",
            configPath: $config['path'],
            configRelativePath: $config['relative_path'],
            configSha256: $config['sha256'],
            environment: $this->environment->build($settings->authtoken),
            settings: $settings,
            upstreamHostHeader: $upstreamHostHeader,
        );
    }

    public function resolveUpstream(): NgrokLocalUpstream
    {
        return $this->upstream->upstream();
    }

    public function resolveUpstreamHostHeader(): string
    {
        return $this->upstream->hostHeader();
    }
}
