<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Support\Facades\File;
use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap\NgrokRuntimeBootstrapper;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeManifestStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;
use SimoneBianco\LaravelNgrokServer\Runtime\Status\NgrokRuntimeStatusService;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;

final class NgrokRuntimeSafetyTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-safety-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_missing_ledger_never_reopens_fresh_bootstrap(): void
    {
        app(NgrokRuntimeBootstrapper::class)->initialize();
        @unlink(app(NgrokRuntimePaths::class)->ledger());

        $status = app(NgrokRuntimeStatusService::class)->status();
        self::assertSame('recovery_required', $status['summary']);
        self::assertSame('initialized', $status['manifest']['state']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been initialized or requires recovery');
        app(NgrokRuntimeBootstrapper::class)->initialize();
    }

    public function test_incomplete_manifest_is_recovery_required_and_cannot_be_bootstrapped_again(): void
    {
        app(NgrokRuntimePaths::class)->ensureRoot();
        app(NgrokRuntimeManifestStore::class)->createInitializing();

        $status = app(NgrokRuntimeStatusService::class)->status();
        self::assertSame('recovery_required', $status['summary']);
        self::assertContains('bootstrap_incomplete', $status['readiness']['reasons']);

        $this->expectException(RuntimeException::class);
        app(NgrokRuntimeBootstrapper::class)->initialize();
    }

    public function test_ledger_without_manifest_is_inconsistent_and_fails_closed(): void
    {
        $paths = app(NgrokRuntimePaths::class);
        $paths->ensureRoot();
        $manifest = app(NgrokRuntimeManifestStore::class)->createInitializing();
        app(NgrokLifecycleLedgerStore::class)->createCleanExclusive($manifest);
        @unlink($paths->manifest());

        $status = app(NgrokRuntimeStatusService::class)->status();
        self::assertSame('recovery_required', $status['summary']);
        self::assertContains('manifest_missing_with_runtime_state', $status['readiness']['reasons']);
    }

    public function test_supervisor_lock_is_exclusive_for_the_installation(): void
    {
        $lock = app(NgrokRuntimeLock::class);
        $first = $lock->acquire();
        self::assertNotNull($first);
        try {
            self::assertNull($lock->acquire());
        } finally {
            $first->release();
        }
        $second = $lock->acquire();
        self::assertNotNull($second);
        $second->release();
    }

    public function test_bootstrap_uses_two_fenced_phases_and_enables_the_spawn_gate_only_after_success(): void
    {
        app(NgrokRuntimeBootstrapper::class)->initialize();

        self::assertSame(2, app(NgrokRuntimeLifecycleFence::class)->calls);
        self::assertSame('enabled', app(NgrokRuntimeSpawnGate::class)->read()['state']);
    }

    public function test_manual_supervisor_refuses_an_initialized_runtime_with_a_disabled_spawn_gate(): void
    {
        app(NgrokRuntimeBootstrapper::class)->initialize();
        $lock = app(NgrokRuntimeLock::class)->acquire();
        self::assertNotNull($lock);
        app(NgrokRuntimeSpawnGate::class)->disable($lock);
        $lock->release();

        self::assertSame(
            NgrokSupervisor::EXIT_RECOVERY_REQUIRED,
            app(NgrokSupervisor::class)->run(static fn (): bool => true),
        );
    }
}
