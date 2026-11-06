<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use ReflectionProperty;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
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
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

final class NgrokSupervisorGenerationTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-generation-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
        app(NgrokRuntimeBootstrapper::class)->initialize();
        $this->ngrokSettings->snapshot = $this->settings(1, 'original-token');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_owned_child_stops_after_settings_change_even_when_snapshot_publication_fails(): void
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
        $process = new GenerationTestProcess;
        $this->setProperty($supervisor, 'manifest', $manifest);
        $this->setProperty($supervisor, 'owned', new OwnedNgrokProcess($process, $launch));
        $this->ngrokSettings->snapshot = $this->settings(2, 'changed-token');
        Http::fake(fn (): mixed => Http::response([], 404));
        $snapshotPath = $paths->snapshot();
        File::delete($snapshotPath);
        File::ensureDirectoryExists($snapshotPath);

        (new ReflectionMethod($supervisor, 'reconcile'))->invoke($supervisor);

        self::assertFalse($process->isRunning());
        self::assertFileDoesNotExist($config);
        self::assertSame('clean', app(NgrokLifecycleLedgerStore::class)->read()['state'] ?? null);
        self::assertDirectoryExists($snapshotPath);
    }

    public function test_obsolete_detached_wake_exits_after_acquiring_the_supervisor_lock(): void
    {
        $supervisor = app(NgrokSupervisor::class);
        $staleGeneration = new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 0);
        $snapshot = app(NgrokRuntimeSnapshotStore::class)->read();

        self::assertSame(0, $supervisor->run(static fn (): bool => false, $staleGeneration));
        self::assertSame($snapshot, app(NgrokRuntimeSnapshotStore::class)->read());
    }

    public function test_disabled_spawn_gate_causes_a_detached_wake_to_exit_after_lock_acquisition(): void
    {
        $lock = app(NgrokRuntimeLock::class)->acquire();
        self::assertNotNull($lock);
        app(NgrokRuntimeSpawnGate::class)->disable($lock);
        $lock->release();

        $supervisor = app(NgrokSupervisor::class);
        $snapshot = app(NgrokRuntimeSnapshotStore::class)->read();

        self::assertSame(0, $supervisor->run(static fn (): bool => false, $this->ngrokSettings->currentVersion()));
        self::assertSame('disabled', app(NgrokRuntimeSpawnGate::class)->read()['state']);
        self::assertSame($snapshot, app(NgrokRuntimeSnapshotStore::class)->read());
    }

    private function settings(int $revision, string $token): NgrokSettingsSnapshot
    {
        return new NgrokSettingsSnapshot(new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', $revision), true, true, RemoteExternalOrigin::fromString('https://approved.ngrok.app'), $token, 'test');
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

final class GenerationTestProcess implements NgrokProcessHandle
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
