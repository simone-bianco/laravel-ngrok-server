<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\TestsStandalone\Support;

use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiAvailabilityPolicy;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiClient;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLockHandle;
use SimoneBianco\LaravelNgrokServer\Process\Binary\NgrokBinary;
use SimoneBianco\LaravelNgrokServer\Process\Binary\NgrokBinaryResolver;
use SimoneBianco\LaravelNgrokServer\Process\Environment\NgrokChildEnvironmentBuilder;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokAgentApiPortAllocator;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokAgentConfigWriter;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparation;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparer;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokManagedProcessFactory;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokProcessHandle;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokProcessLauncher;
use SimoneBianco\LaravelNgrokServer\Process\Launch\OwnedNgrokProcess;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnershipResolver;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokProbeStabilityPolicy;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorShutdownSignal;
use SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap\NgrokRuntimeBootstrapper;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\WindowsAtomicFileReplacer;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\ConfiguredNgrokRuntimeUpstreamProvider;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\NgrokRuntimeIdentityProbe;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\RuntimeIdentityObservation;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSnapshotStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokSettingsGeneration;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeRemotePolicy;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeSettings;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokHostHeaderPolicy;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

/**
 * Minimal, tunnel-free supervisor harness for environments where the package
 * Testbench suite cannot be installed. It exercises the real supervisor,
 * ledger, snapshot and filesystem artifacts with fake process/HTTP probes.
 */
final class SupervisorHarness
{
    public readonly string $root;

    public readonly NgrokRuntimePaths $paths;

    public readonly DurableRuntimeFile $files;

    public readonly NgrokRuntimeManifestStore $manifests;

    public readonly NgrokLifecycleLedgerStore $ledgers;

    public readonly NgrokRuntimeSnapshotStore $snapshots;

    public readonly FakeSettings $settings;

    public readonly FakeIdentityProbe $identity;

    public readonly NgrokSupervisor $supervisor;

    public string $agentApiMode = 'unavailable';

    public ?OwnedNgrokProcess $owned = null;

    private readonly Factory $http;

    public function __construct(string $root)
    {
        $this->root = $root;
        config([
            'ngrok-server.runtime_root' => $root,
            'ngrok-server.upstream_url' => 'http://127.0.0.1:8000',
            'ngrok-server.runtime_probe_failure_grace_ms' => 30000,
            'ngrok-server.agent_api_startup_grace_ms' => 5000,
            'ngrok-server.runtime_identity_probe_timeout_ms' => 5000,
            'ngrok-server.runtime_identity_probe_degraded_interval_ms' => 3000,
        ]);

        $replacer = new WindowsAtomicFileReplacer(
            ffiFactory: static fn (): mixed => throw new RuntimeException('FFI is disabled in standalone tests.'),
            helperReplace: static function (string $source, string $destination): void {
                if (is_file($destination) && ! @unlink($destination)) {
                    throw new RuntimeException('Unable to remove the standalone destination file.');
                }
                if (! @rename($source, $destination)) {
                    throw new RuntimeException('Unable to replace the standalone runtime file.');
                }
            },
        );
        $this->files = new DurableRuntimeFile($replacer);
        $this->paths = new NgrokRuntimePaths;
        $this->manifests = new NgrokRuntimeManifestStore($this->paths, $this->files);
        $this->ledgers = new NgrokLifecycleLedgerStore($this->paths, $this->files);
        $this->snapshots = new NgrokRuntimeSnapshotStore($this->paths, $this->files);
        $gate = new NgrokRuntimeSpawnGate($this->paths, $this->files);
        $fence = new FakeLifecycleFence;
        $lock = new FakeRuntimeLock;
        $this->settings = new FakeSettings(new NgrokSettingsSnapshot(
            new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 0),
            true,
            false,
            null,
            null,
            'none',
        ));
        $this->identity = new FakeIdentityProbe;
        $debugPolicy = new FakeRemotePolicy(false);

        $bootstrapper = new NgrokRuntimeBootstrapper(
            $this->paths,
            $lock,
            $this->manifests,
            $this->ledgers,
            $this->snapshots,
            $gate,
            $fence,
            $this->settings,
        );
        $bootstrapper->initialize();
        $this->settings->snapshot = new NgrokSettingsSnapshot(
            new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1),
            true,
            true,
            RemoteExternalOrigin::fromString('https://approved.ngrok.app'),
            'standalone-token',
            'test',
        );

        $this->http = new Factory;
        $this->http->fake(fn (ClientRequest $request): mixed => $this->fakeAgentApi($request));

        $preparer = new NgrokLaunchPreparer(
            $this->uninitialized(NgrokBinaryResolver::class),
            $this->uninitialized(NgrokAgentApiPortAllocator::class),
            $this->uninitialized(NgrokAgentConfigWriter::class),
            $this->uninitialized(NgrokChildEnvironmentBuilder::class),
            $this->uninitialized(NgrokProcessOwnershipResolver::class),
            new FakeLaunchRemotePolicy,
            new ConfiguredNgrokRuntimeUpstreamProvider,
        );
        $launcher = new NgrokProcessLauncher($this->uninitialized(NgrokManagedProcessFactory::class));
        $shutdownSignal = new NgrokSupervisorShutdownSignal($this->paths, $this->files);

        $this->supervisor = new NgrokSupervisor(
            lock: $lock,
            manifests: $this->manifests,
            ledgers: $this->ledgers,
            snapshots: $this->snapshots,
            fence: $fence,
            settings: $this->settings,
            gate: $gate,
            settingsGeneration: new NgrokSettingsGeneration,
            preparer: $preparer,
            processes: $launcher,
            agentApi: new NgrokAgentApiClient($this->http),
            agentApiAvailability: new NgrokAgentApiAvailabilityPolicy,
            probeStability: new NgrokProbeStabilityPolicy,
            identity: $this->identity,
            debugPolicy: $debugPolicy,
            shutdownSignal: $shutdownSignal,
        );

        $manifest = $this->manifests->read();
        if (! is_array($manifest)) {
            throw new RuntimeException('Standalone harness failed to bootstrap the runtime.');
        }
        $this->setProperty($this->supervisor, 'manifest', $manifest);
    }

    public function reconcile(): void
    {
        (new ReflectionMethod($this->supervisor, 'reconcile'))->invoke($this->supervisor);
    }

    public function attachOwned(?float $startedAt = null): OwnedNgrokProcess
    {
        $manifest = $this->manifests->read();
        if (! is_array($manifest)) {
            throw new RuntimeException('Standalone harness manifest is missing.');
        }
        $attempt = bin2hex(random_bytes(16));
        $endpoint = bin2hex(random_bytes(16));
        $config = $this->paths->config($attempt);
        if (! is_dir(dirname($config)) && ! @mkdir(dirname($config), 0700, true) && ! is_dir(dirname($config))) {
            throw new RuntimeException('Unable to create the standalone config directory.');
        }
        file_put_contents($config, "version: 3\n");
        $ownership = new NgrokProcessOwnership(
            NgrokProcessOwnership::WINDOWS_JOB,
            'Local\\StandaloneNgrok-'.$attempt,
        );
        $supervisorId = $this->property($this->supervisor, 'supervisorInstanceId');
        $bootEpochId = $this->property($this->supervisor, 'bootEpochId');
        $this->ledgers->arm($manifest, [
            'spawn_attempt_id' => $attempt,
            'endpoint_instance_id' => $endpoint,
            'supervisor_instance_id' => $supervisorId,
            'settings_version' => $this->settings->snapshot->version->toArray(),
            'boot_epoch_id' => $bootEpochId,
            'process_ownership_type' => $ownership->type,
            'process_ownership_id' => $ownership->id,
            'agent_api_address' => 'http://127.0.0.1:4098',
            'canonical_origin' => 'https://approved.ngrok.app',
            'canonical_upstream' => 'http://127.0.0.1:8000',
            'runtime_config_path' => 'configs/'.$attempt.'.yml',
            'runtime_config_sha256' => hash_file('sha256', $config),
        ]);
        $this->ledgers->markActive($manifest, $attempt, 4242);

        $launch = new NgrokLaunchPreparation(
            spawnAttemptId: $attempt,
            endpointInstanceId: $endpoint,
            supervisorInstanceId: $supervisorId,
            bootEpochId: $bootEpochId,
            ownership: $ownership,
            binary: new NgrokBinary(PHP_BINARY, 'standalone-child'),
            origin: RemoteExternalOrigin::fromString('https://approved.ngrok.app'),
            upstream: NgrokLocalUpstream::fromString('http://127.0.0.1:8000'),
            agentApiPort: 4098,
            agentApiAddress: 'http://127.0.0.1:4098',
            configPath: $config,
            configRelativePath: 'configs/'.$attempt.'.yml',
            configSha256: hash_file('sha256', $config),
            environment: [],
            settings: $this->settings->snapshot,
            upstreamHostHeader: 'approved.ngrok.app',
        );
        $this->owned = new OwnedNgrokProcess(new HarnessProcessHandle, $launch, $startedAt);
        $this->setProperty($this->supervisor, 'owned', $this->owned);
        $this->setProperty($this->supervisor, 'manifest', $manifest);

        return $this->owned;
    }

    public function replaceOwned(OwnedNgrokProcess $owned): void
    {
        $this->owned = $owned;
        $this->setProperty($this->supervisor, 'owned', $owned);
    }

    public function snapshot(): array
    {
        $snapshot = $this->snapshots->read();
        if (! is_array($snapshot)) {
            throw new RuntimeException('Standalone harness snapshot is missing.');
        }

        return $snapshot;
    }

    public function ledger(): array
    {
        $ledger = $this->ledgers->read();
        if (! is_array($ledger)) {
            throw new RuntimeException('Standalone harness ledger is missing.');
        }

        return $ledger;
    }

    public function clearIdentityProbeRetry(): void
    {
        $this->setProperty($this->supervisor, 'nextIdentityProbeAt', 0.0);
    }

    public function setIdentityUnavailableSince(?float $since): void
    {
        $this->setProperty($this->supervisor, 'identityUnavailableSince', $since);
    }

    public function setIdentityVerifiedAt(?float $at): void
    {
        $this->setProperty($this->supervisor, 'identityVerifiedAt', $at);
    }

    /** @param list<string> $states */
    public function queueIdentity(array $states): void
    {
        $this->identity->queue($states);
    }

    public function setIdentityCallback(?Closure $callback): void
    {
        $this->identity->onObserve = $callback;
    }

    public function setAgentApiMode(string $mode): void
    {
        $this->agentApiMode = $mode;
    }

    public function setAgentApiUnavailableSince(?float $since): void
    {
        $this->setProperty($this->supervisor, 'agentApiUnavailableSince', $since);
    }

    private function fakeAgentApi(ClientRequest $request): mixed
    {
        if (! str_ends_with($request->url(), '/api/endpoints')) {
            return $this->http->response([], 404);
        }
        if ($this->owned === null || ! $this->owned->process->isRunning()) {
            return $this->http->response([], 503);
        }
        if ($this->agentApiMode !== 'ready' && $this->agentApiMode !== 'mismatch') {
            return $this->http->response([], 503);
        }
        $launch = $this->owned->launch;
        $url = $this->agentApiMode === 'mismatch'
            ? 'https://other.ngrok.app'
            : $launch->origin->value();

        return $this->http->response([
            'endpoints' => [[
                'name' => $launch->endpointInstanceId,
                'url' => $url,
                'upstream' => ['url' => $launch->upstream->value()],
                'inspect' => false,
                'pooling_enabled' => false,
                'traffic_policy' => json_encode(
                    NgrokHostHeaderPolicy::definition($launch->upstreamHostHeader),
                    JSON_THROW_ON_ERROR,
                ),
            ]],
        ], 200);
    }

    private function property(object $target, string $name): mixed
    {
        return (new ReflectionProperty($target, $name))->getValue($target);
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        (new ReflectionProperty($target, $name))->setValue($target, $value);
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function uninitialized(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

final class FakeLaunchRemotePolicy implements NgrokRemoteAccessPolicy
{
    public function blocksRemoteAccess(): bool
    {
        return false;
    }
}

final class FakeRuntimeLock implements NgrokRuntimeLock
{
    public function acquire(): ?NgrokRuntimeLockHandle
    {
        return new FakeRuntimeLockHandle;
    }
}final class FakeRuntimeLockHandle implements NgrokRuntimeLockHandle
{
    private readonly string $claim;

    public function __construct()
    {
        $this->claim = bin2hex(random_bytes(16));
    }

    public function ownerClaim(): string
    {
        return $this->claim;
    }

    public function isHeld(): bool
    {
        return true;
    }

    public function release(): void {}
}

final class FakeIdentityProbe implements NgrokRuntimeIdentityProbe
{
    /** @var list<string> */
    private array $states = [];

    /** @var Closure(): void|null */
    public ?Closure $onObserve = null;

    public int $calls = 0;

    /** @param list<string> $states */
    public function queue(array $states): void
    {
        $this->states = $states;
    }

    public function observe(NgrokLocalUpstream $upstream, string $hostHeader = ''): RuntimeIdentityObservation
    {
        $this->calls++;
        if ($this->onObserve !== null) {
            ($this->onObserve)();
        }
        $state = $this->states === [] ? RuntimeIdentityObservation::UNAVAILABLE : array_shift($this->states);

        return match ($state) {
            RuntimeIdentityObservation::READY => RuntimeIdentityObservation::ready(),
            RuntimeIdentityObservation::MISMATCH => RuntimeIdentityObservation::mismatch(),
            default => RuntimeIdentityObservation::unavailable(),
        };
    }

    public function healthy(NgrokLocalUpstream $upstream, string $hostHeader = ''): bool
    {
        return $this->observe($upstream, $hostHeader)->state === RuntimeIdentityObservation::READY;
    }
}

final class HarnessProcessHandle implements NgrokProcessHandle
{
    public bool $running = true;

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getPid(): ?int
    {
        return 4242;
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
        return 'standalone-child';
    }
}
