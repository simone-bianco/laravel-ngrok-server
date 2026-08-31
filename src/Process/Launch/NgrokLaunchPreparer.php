<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRemoteAccessPolicy;
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
        $config = $this->configs->write($attempt, $port);
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
        return NgrokLocalUpstream::fromString((string) config('ngrok-server.upstream_url'));
    }

    public function resolveUpstreamHostHeader(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('APP_URL must define the ngrok upstream Host header.');
        }
        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        if (strlen($host) > 253
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) !== 1) {
            throw new RuntimeException('APP_URL contains an invalid ngrok upstream Host header.');
        }

        return $host;
    }
}
