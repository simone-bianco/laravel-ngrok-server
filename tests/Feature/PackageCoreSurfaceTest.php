<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiAvailabilityPolicy;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiClient;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiObservation;
use SimoneBianco\LaravelNgrokServer\Process\Binary\NgrokBinary;
use SimoneBianco\LaravelNgrokServer\Process\Environment\NgrokChildEnvironmentBuilder;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokAgentConfigWriter;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparation;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokProcessHandle;
use SimoneBianco\LaravelNgrokServer\Process\Launch\OwnedNgrokProcess;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

final class PackageCoreSurfaceTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-package-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_agent_config_is_secret_free_and_exclusive(): void
    {
        $attempt = str_repeat('a', 32);
        $config = app(NgrokAgentConfigWriter::class)->write($attempt, 4041);
        $contents = File::get($config['path']);

        self::assertStringContainsString('web_addr: 127.0.0.1:4041', $contents);
        self::assertStringContainsString('inspect_db_size: -1', $contents);
        self::assertStringNotContainsString('authtoken', strtolower($contents));
        $this->expectException(\RuntimeException::class);
        app(NgrokAgentConfigWriter::class)->write($attempt, 4041);
    }

    public function test_environment_is_deny_by_default_except_for_the_ngrok_token(): void
    {
        putenv('APP_KEY=parent-secret');
        putenv('NGROK_API_KEY=parent-api-key');
        try {
            $environment = (new NgrokChildEnvironmentBuilder)->build('application-token');
            self::assertSame('application-token', $environment['NGROK_AUTHTOKEN']);
            self::assertFalse($environment['APP_KEY']);
            self::assertFalse($environment['NGROK_API_KEY']);
        } finally {
            putenv('APP_KEY');
            putenv('NGROK_API_KEY');
        }
    }

    public function test_agent_api_requires_the_exact_owned_endpoint_and_grace_uses_package_config(): void
    {
        $launch = $this->launch();
        Http::fake([$launch->agentApiAddress.'/api/endpoints' => Http::response(['endpoints' => [[
            'name' => $launch->endpointInstanceId,
            'url' => $launch->origin->value(),
            'upstream' => ['url' => $launch->upstream->value()],
            'inspect' => false,
            'pooling_enabled' => false,
        ]]])]);
        self::assertSame(NgrokAgentApiObservation::READY, app(NgrokAgentApiClient::class)->observe($launch)->state);

        config()->set('ngrok-server.agent_api_startup_grace_ms', 5000);
        $owned = new OwnedNgrokProcess(new RunningHandle, $launch, 100.0);
        self::assertFalse(app(NgrokAgentApiAvailabilityPolicy::class)->startupGraceExpired($owned, 104.999));
        self::assertTrue(app(NgrokAgentApiAvailabilityPolicy::class)->startupGraceExpired($owned, 105.0));
    }

    public function test_agent_api_rejects_duplicate_or_mutated_owned_endpoints_and_marks_missing_endpoints_unavailable(): void
    {
        $launch = $this->launch();
        $client = app(NgrokAgentApiClient::class);
        $endpoint = [
            'name' => $launch->endpointInstanceId,
            'url' => $launch->origin->value(),
            'upstream' => ['url' => $launch->upstream->value()],
            'inspect' => false,
            'pooling_enabled' => false,
        ];

        Http::fakeSequence()
            ->push(['endpoints' => [$endpoint, $endpoint]])
            ->push(['endpoints' => [array_replace($endpoint, ['inspect' => true])]])
            ->push(['endpoints' => [array_replace_recursive($endpoint, ['upstream' => ['url' => 'http://127.0.0.1:9000']])]])
            ->push(['endpoints' => []])
            ->push([], 503);
        self::assertSame(NgrokAgentApiObservation::MISMATCH, $client->observe($launch)->state);

        self::assertSame(NgrokAgentApiObservation::MISMATCH, $client->observe($launch)->state);

        self::assertSame(NgrokAgentApiObservation::MISMATCH, $client->observe($launch)->state);

        self::assertSame(NgrokAgentApiObservation::UNAVAILABLE, $client->observe($launch)->state);

        self::assertSame(NgrokAgentApiObservation::UNAVAILABLE, $client->observe($launch)->state);
    }

    private function launch(): NgrokLaunchPreparation
    {
        $origin = RemoteExternalOrigin::fromString('https://example.ngrok.app');

        return new NgrokLaunchPreparation(
            spawnAttemptId: str_repeat('a', 32), endpointInstanceId: str_repeat('b', 32),
            supervisorInstanceId: str_repeat('c', 32), bootEpochId: str_repeat('d', 32),
            ownership: new NgrokProcessOwnership(PHP_OS_FAMILY === 'Windows' ? NgrokProcessOwnership::WINDOWS_JOB : NgrokProcessOwnership::POSIX_PROCESS, PHP_OS_FAMILY === 'Windows' ? 'Local\\PackageCoreSurface' : null),
            binary: new NgrokBinary('ngrok', 'ngrok version 3'), origin: $origin,
            upstream: NgrokLocalUpstream::fromString('http://127.0.0.1:8000'), agentApiPort: 4041,
            agentApiAddress: 'http://127.0.0.1:4041', configPath: $this->runtimeRoot.'/config.yml',
            configRelativePath: 'configs/'.str_repeat('a', 32).'.yml', configSha256: hash('sha256', 'config'), environment: [],
            settings: new NgrokSettingsSnapshot(new NgrokSettingsVersion('00000000000000000000000000', 1), true, true, $origin, 'token', 'test'),
        );
    }
}

final class RunningHandle implements NgrokProcessHandle
{
    public function isRunning(): bool
    {
        return true;
    }

    public function getPid(): ?int
    {
        return 1234;
    }

    public function getExitCode(): ?int
    {
        return null;
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        return 0;
    }

    public function getTimeout(): ?float
    {
        return null;
    }

    public function getIdleTimeout(): ?float
    {
        return null;
    }

    public function isOutputDisabled(): bool
    {
        return true;
    }

    public function getCommandLine(): string
    {
        return 'package-test';
    }
}
