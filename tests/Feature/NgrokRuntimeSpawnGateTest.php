<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Support\Facades\File;
use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSpawnGate;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;

final class NgrokRuntimeSpawnGateTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-spawn-gate-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_missing_or_crash_disabled_gate_fails_closed_until_a_lock_owner_reclaims_and_enables_it(): void
    {
        $gate = app(NgrokRuntimeSpawnGate::class);
        $lock = app(NgrokRuntimeLock::class);

        try {
            $gate->assertEnabled();
            self::fail('A missing spawn gate must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('spawn gate is missing', $exception->getMessage());
        }

        $first = $lock->acquire();
        self::assertNotNull($first);
        $gate->disable($first);
        self::assertSame('disabled', $gate->read()['state']);
        $first->release();

        $second = $lock->acquire();
        self::assertNotNull($second);
        try {
            $gate->reclaim($second);
            self::assertSame($second->ownerClaim(), $gate->read()['owner_claim']);
            $gate->enable($second);
            $gate->assertEnabled();
        } finally {
            $second->release();
        }

        self::assertFileExists(app(NgrokRuntimePaths::class)->spawnGate());
    }
}
