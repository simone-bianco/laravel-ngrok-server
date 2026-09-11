<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Support\Facades\File;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnershipResolver;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\AgentApiReachabilityProbe;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokRuntimeRecoveryService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorShutdownSignal;
use SimoneBianco\LaravelNgrokServer\Process\Windows\WindowsJobObjectInspector;
use SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap\NgrokRuntimeBootstrapper;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;

final class NgrokRuntimeRecoveryTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-recovery-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_recovery_rejects_an_uncertain_attempt_when_the_agent_api_remains_reachable(): void
    {
        $this->bootstrapAndArmAttempt();
        $probe = new ScriptedReachabilityProbe(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent API is still reachable');

        $this->recovery($probe)->recover();
    }

    public function test_clean_recovery_clears_an_interrupted_launcher_shutdown_before_reenabling_start(): void
    {
        app(NgrokRuntimeBootstrapper::class)->initialize();
        app(NgrokSupervisorShutdownSignal::class)->request('interrupted_launcher');
        self::assertSame('already_clean', $this->recovery(new ScriptedReachabilityProbe(true))->recover());
        self::assertFalse(app(NgrokSupervisorShutdownSignal::class)->requested());
        self::assertSame('enabled', app(NgrokRuntimeSpawnGate::class)->read()['state']);
    }

    public function test_recovery_cleans_a_quiescent_attempt_and_removes_its_runtime_config(): void
    {
        [$attempt, $config] = $this->bootstrapAndArmAttempt();
        $recovery = $this->recovery(new ScriptedReachabilityProbe(true));
        app(NgrokSupervisorShutdownSignal::class)->request('interrupted_launcher');

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('handle-bound Windows Job Object ownership');
            $recovery->recover();

            return;
        }

        self::assertSame('recovered', $recovery->recover());
        self::assertSame('clean', app(NgrokLifecycleLedgerStore::class)->read()['state'] ?? null);
        self::assertFileDoesNotExist($config);
        self::assertFalse(app(NgrokSupervisorShutdownSignal::class)->requested());
        self::assertSame(4, app(NgrokRuntimeLifecycleFence::class)->calls);
        self::assertSame('enabled', app(NgrokRuntimeSpawnGate::class)->read()['state']);
    }

    public function test_failed_recovery_leaves_the_gate_disabled_after_its_first_fenced_phase(): void
    {
        $this->bootstrapAndArmAttempt();
        $fence = app(NgrokRuntimeLifecycleFence::class);
        self::assertInstanceOf(FakeLifecycleFence::class, $fence);

        try {
            $this->recovery(new ScriptedReachabilityProbe(false))->recover();
            self::fail('Recovery must refuse a reachable agent API.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Agent API is still reachable', $exception->getMessage());
        }

        self::assertSame(3, $fence->calls);
        self::assertSame('disabled', app(NgrokRuntimeSpawnGate::class)->read()['state']);
    }

    public function test_recovery_refuses_to_clean_when_the_owned_job_object_reappears_inside_fence_b(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Recovery Fence B Job Object verification requires Windows.');
        }

        $this->bootstrapAndArmAttempt();
        $recovery = $this->recovery(new ScriptedReachabilityProbe(true), new ReappearingWindowsJobObjectInspector);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows Job Object reappeared');

        $recovery->recover();
    }

    /** @return array{string, string} */
    private function bootstrapAndArmAttempt(): array
    {
        app(NgrokRuntimeBootstrapper::class)->initialize();
        $manifest = app(NgrokRuntimeManifestStore::class)->read();
        self::assertIsArray($manifest);
        $attempt = bin2hex(random_bytes(16));
        $paths = app(NgrokRuntimePaths::class);
        $config = $paths->config($attempt);
        File::ensureDirectoryExists(dirname($config));
        file_put_contents($config, "version: 3\n");
        app(NgrokLifecycleLedgerStore::class)->arm($manifest, [
            'spawn_attempt_id' => $attempt,
            'endpoint_instance_id' => bin2hex(random_bytes(16)),
            'supervisor_instance_id' => bin2hex(random_bytes(16)),
            'settings_version' => $this->ngrokSettings->currentVersion()->toArray(),
            'boot_epoch_id' => bin2hex(random_bytes(16)),
            'process_ownership_type' => app(NgrokProcessOwnershipResolver::class)->forAttempt($attempt)->type,
            'process_ownership_id' => app(NgrokProcessOwnershipResolver::class)->forAttempt($attempt)->id,
            'agent_api_address' => 'http://127.0.0.1:4041',
            'canonical_origin' => 'https://recovery.ngrok.app',
            'canonical_upstream' => 'http://127.0.0.1:8000',
            'runtime_config_path' => 'configs/'.$attempt.'.yml',
            'runtime_config_sha256' => hash_file('sha256', $config),
        ]);

        return [$attempt, $config];
    }

    private function recovery(
        AgentApiReachabilityProbe $probe,
        ?WindowsJobObjectInspector $windowsJobs = null,
    ): NgrokRuntimeRecoveryService {
        return new NgrokRuntimeRecoveryService(
            app(NgrokRuntimeLock::class),
            app(NgrokRuntimeManifestStore::class),
            app(NgrokLifecycleLedgerStore::class),
            app(NgrokProcessOwnershipResolver::class),
            $windowsJobs ?? app(WindowsJobObjectInspector::class),
            app(NgrokRuntimePaths::class),
            app(NgrokRuntimeLifecycleFence::class),
            app(NgrokRuntimeSettingsSnapshotProvider::class),
            app(NgrokSupervisorShutdownSignal::class),
            $probe,
            app(NgrokRuntimeSpawnGate::class),
        );
    }
}

final class ScriptedReachabilityProbe implements AgentApiReachabilityProbe
{
    public function __construct(private bool $unavailable) {}

    public function unavailable(string $address): bool
    {
        return $this->unavailable;
    }
}

final class ReappearingWindowsJobObjectInspector extends WindowsJobObjectInspector
{
    private int $checks = 0;

    public function exists(NgrokProcessOwnership $ownership): bool
    {
        $this->checks++;

        return $this->checks > 1;
    }
}
