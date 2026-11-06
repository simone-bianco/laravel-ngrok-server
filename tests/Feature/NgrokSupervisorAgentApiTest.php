<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use ReflectionProperty;
use SimoneBianco\LaravelNgrokServer\Process\Binary\NgrokBinary;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparation;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokProcessHandle;
use SimoneBianco\LaravelNgrokServer\Process\Launch\OwnedNgrokProcess;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnershipResolver;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap\NgrokRuntimeBootstrapper;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSnapshotStore;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokHostHeaderPolicy;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

final class NgrokSupervisorAgentApiTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-agent-api-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
        app(NgrokRuntimeBootstrapper::class)->initialize();
        $this->ngrokSettings->snapshot = $this->settings(1);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_exact_endpoint_mismatch_stops_the_owned_child_without_startup_grace(): void
    {
        config()->set('ngrok-server.agent_api_startup_grace_ms', 60_000);
        [$supervisor, $owned] = $this->ownedSupervisor();
        $observations = 0;
        Http::fake(function (Request $request) use ($owned, &$observations): mixed {
            if ($request->url() === $owned->launch->upstream->identityUrl()) {
                return Http::response(['protocol_version' => 1, 'mac' => 'valid']);
            }
            $observations++;

            return $observations === 1
                ? Http::response(['endpoints' => [$this->endpoint($owned->launch, ['url' => 'https://other.ngrok.app'])]])
                : Http::response([], 404);
        });

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        $this->assertStoppedWithReason($owned, 'agent_api_endpoint_mismatch');
    }

    public function test_transient_agent_api_unavailability_keeps_owned_child_running_during_failure_grace(): void
    {
        config()->set('ngrok-server.agent_api_startup_grace_ms', 100);
        config()->set('ngrok-server.runtime_probe_failure_grace_ms', 60_000);
        [$supervisor, $owned] = $this->ownedSupervisor(microtime(true) - 1);
        Http::fake(function (Request $request) use ($owned): mixed {
            if ($request->url() === $owned->launch->upstream->identityUrl()) {
                return Http::response(['protocol_version' => 1, 'mac' => 'valid']);
            }

            return Http::response([], 503);
        });

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        $this->assertRunningWithReason($owned, 'agent_api_temporarily_unavailable');
    }

    public function test_missing_host_policy_stops_the_owned_child_even_when_endpoint_and_upstream_are_online(): void
    {
        [$supervisor, $owned] = $this->ownedSupervisor();
        $observations = 0;
        Http::fake(function (Request $request) use ($owned, &$observations): mixed {
            if ($request->url() === $owned->launch->upstream->identityUrl()) {
                return Http::response(['protocol_version' => 1, 'mac' => 'valid']);
            }
            $endpoint = $this->endpoint($owned->launch);
            unset($endpoint['traffic_policy']);

            return ++$observations === 1
                ? Http::response(['endpoints' => [$endpoint]])
                : Http::response([], 404);
        });

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        $this->assertStoppedWithReason($owned, 'agent_api_endpoint_mismatch');
    }

    public function test_agent_api_unavailability_stops_owned_child_after_failure_grace(): void
    {
        config()->set('ngrok-server.agent_api_startup_grace_ms', 100);
        config()->set('ngrok-server.runtime_probe_failure_grace_ms', 100);
        [$supervisor, $owned] = $this->ownedSupervisor(microtime(true) - 1);
        $this->setProperty($supervisor, 'agentApiUnavailableSince', microtime(true) - 1);
        Http::fake(function (Request $request) use ($owned): mixed {
            if ($request->url() === $owned->launch->upstream->identityUrl()) {
                return Http::response(['protocol_version' => 1, 'mac' => 'valid']);
            }

            return Http::response([], 503);
        });

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        $this->assertStoppedWithReason($owned, 'agent_api_unavailable');
    }

    public function test_transient_upstream_identity_unavailability_keeps_owned_child_running(): void
    {
        config()->set('ngrok-server.runtime_probe_failure_grace_ms', 60_000);
        [$supervisor, $owned] = $this->ownedSupervisor(microtime(true) - 1);
        Http::fake(fn (Request $request): mixed => $request->url() === $owned->launch->upstream->identityUrl()
            ? Http::response([], 503)
            : Http::response([], 500));

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        $this->assertRunningWithReason($owned, 'upstream_identity_temporarily_unavailable');
    }

    public function test_upstream_identity_unavailability_stops_owned_child_after_failure_grace(): void
    {
        config()->set('ngrok-server.runtime_probe_failure_grace_ms', 100);
        [$supervisor, $owned] = $this->ownedSupervisor(microtime(true) - 1);
        $this->setProperty($supervisor, 'identityUnavailableSince', microtime(true) - 1);
        Http::fake(fn (Request $request): mixed => $request->url() === $owned->launch->upstream->identityUrl()
            ? Http::response([], 503)
            : Http::response([], 500));

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        $this->assertStoppedWithReason($owned, 'upstream_identity_unavailable');
    }

    public function test_upstream_identity_mismatch_stops_owned_child_immediately(): void
    {
        config()->set('ngrok-server.runtime_probe_failure_grace_ms', 60_000);
        [$supervisor, $owned] = $this->ownedSupervisor(microtime(true) - 1);
        Http::fake(fn (Request $request): mixed => $request->url() === $owned->launch->upstream->identityUrl()
            ? Http::response(['protocol_version' => 1, 'mac' => 'invalid'])
            : Http::response([], 500));

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        $this->assertStoppedWithReason($owned, 'upstream_identity_mismatch');
    }

    /** @return array{NgrokSupervisor, OwnedNgrokProcess} */
    private function ownedSupervisor(?float $startedAt = null): array
    {
        $supervisor = app(NgrokSupervisor::class);
        $manifest = app(NgrokRuntimeManifestStore::class)->read();
        self::assertIsArray($manifest);
        $attempt = bin2hex(random_bytes(16));
        $paths = app(NgrokRuntimePaths::class);
        $config = $paths->config($attempt);
        File::ensureDirectoryExists(dirname($config));
        file_put_contents($config, "version: 3\n");
        $ownership = app(NgrokProcessOwnershipResolver::class)->forAttempt($attempt);
        $supervisorId = $this->property($supervisor, 'supervisorInstanceId');
        $bootEpochId = $this->property($supervisor, 'bootEpochId');
        $endpoint = bin2hex(random_bytes(16));
        app(NgrokLifecycleLedgerStore::class)->arm($manifest, [
            'spawn_attempt_id' => $attempt,
            'endpoint_instance_id' => $endpoint,
            'supervisor_instance_id' => $supervisorId,
            'settings_version' => $this->ngrokSettings->currentVersion()->toArray(),
            'boot_epoch_id' => $bootEpochId,
            'process_ownership_type' => $ownership->type,
            'process_ownership_id' => $ownership->id,
            'agent_api_address' => 'http://127.0.0.1:4098',
            'canonical_origin' => 'https://approved.ngrok.app',
            'canonical_upstream' => 'http://127.0.0.1:8000',
            'runtime_config_path' => 'configs/'.$attempt.'.yml',
            'runtime_config_sha256' => hash_file('sha256', $config),
        ]);
        app(NgrokLifecycleLedgerStore::class)->markActive($manifest, $attempt, 1234);
        $launch = new NgrokLaunchPreparation(
            $attempt,
            $endpoint,
            $supervisorId,
            $bootEpochId,
            $ownership,
            new NgrokBinary(PHP_BINARY, 'test-child'),
            RemoteExternalOrigin::fromString('https://approved.ngrok.app'),
            NgrokLocalUpstream::fromString('http://127.0.0.1:8000'),
            4098,
            'http://127.0.0.1:4098',
            $config,
            'configs/'.$attempt.'.yml',
            hash_file('sha256', $config),
            [],
            $this->ngrokSettings->capture(),
            'localhost',
        );
        $owned = new OwnedNgrokProcess(new AgentApiTestProcess, $launch, $startedAt);
        $this->setProperty($supervisor, 'manifest', $manifest);
        $this->setProperty($supervisor, 'owned', $owned);

        return [$supervisor, $owned];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function endpoint(NgrokLaunchPreparation $launch, array $changes = []): array
    {
        return array_replace_recursive([
            'name' => $launch->endpointInstanceId,
            'url' => $launch->origin->value(),
            'upstream' => ['url' => $launch->upstream->value()],
            'inspect' => false,
            'pooling_enabled' => false,
            'traffic_policy' => json_encode(NgrokHostHeaderPolicy::definition($launch->upstreamHostHeader), JSON_THROW_ON_ERROR),
        ], $changes);
    }

    private function assertRunningWithReason(OwnedNgrokProcess $owned, string $reason): void
    {
        self::assertTrue($owned->process->isRunning());
        self::assertFileExists($owned->launch->configPath);
        self::assertSame('child_active', app(NgrokLifecycleLedgerStore::class)->read()['state'] ?? null);
        $snapshot = app(NgrokRuntimeSnapshotStore::class)->read();
        self::assertSame('degraded', $snapshot['summary'] ?? null);
        self::assertContains($reason, $snapshot['readiness']['reasons'] ?? []);
    }

    private function assertStoppedWithReason(OwnedNgrokProcess $owned, string $reason): void
    {
        self::assertFalse($owned->process->isRunning());
        self::assertFileDoesNotExist($owned->launch->configPath);
        self::assertSame('clean', app(NgrokLifecycleLedgerStore::class)->read()['state'] ?? null);
        $snapshot = app(NgrokRuntimeSnapshotStore::class)->read();
        self::assertSame('blocked', $snapshot['summary'] ?? null);
        self::assertContains($reason, $snapshot['readiness']['reasons'] ?? []);
    }

    private function settings(int $revision): NgrokSettingsSnapshot
    {
        return new NgrokSettingsSnapshot(new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', $revision), true, true, RemoteExternalOrigin::fromString('https://approved.ngrok.app'), 'token-'.$revision, 'test');
    }

    private function property(object $target, string $name): mixed
    {
        return (new ReflectionProperty($target, $name))->getValue($target);
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        (new ReflectionProperty($target, $name))->setValue($target, $value);
    }
}

final class AgentApiTestProcess implements NgrokProcessHandle
{
    private bool $running = true;

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getPid(): ?int
    {
        return 1234;
    }

    public function getExitCode(): ?int
    {
        return $this->running ? null : 0;
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        $this->running = false;

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
        return 'test-child';
    }
}
