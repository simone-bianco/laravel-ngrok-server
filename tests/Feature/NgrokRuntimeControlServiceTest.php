<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Support\Facades\File;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokSupervisorSpawner;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDesiredStateStore;
use SimoneBianco\LaravelNgrokServer\Process\Control\NgrokRuntimeControlService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorShutdownSignal;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeDesiredStateStore;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class NgrokRuntimeControlServiceTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-control-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_changed_start_wakes_once_with_its_committed_generation_and_unchanged_start_does_not_wake(): void
    {
        $desired = app(NgrokDesiredStateStore::class);
        self::assertInstanceOf(FakeDesiredStateStore::class, $desired);
        $spawner = new RecordingSpawner;
        $service = new NgrokRuntimeControlService($desired, $spawner, app(NgrokSupervisorShutdownSignal::class));
        $expected = $this->ngrokSettings->currentVersion();

        $changed = $service->start($expected);
        $unchanged = $service->start($changed->version);

        self::assertTrue($changed->changed);
        self::assertFalse($unchanged->changed);
        self::assertTrue($desired->requiredSpawnGate);
        self::assertSame([$changed->version->toArray()], $spawner->generations);
    }

    public function test_changed_stop_publishes_shutdown_after_the_mutation_and_unchanged_stop_does_not_replace_it(): void
    {
        $desired = app(NgrokDesiredStateStore::class);
        self::assertInstanceOf(FakeDesiredStateStore::class, $desired);
        $service = new NgrokRuntimeControlService($desired, new RecordingSpawner, app(NgrokSupervisorShutdownSignal::class));
        $started = $service->start($this->ngrokSettings->currentVersion());

        $stopped = $service->stop($started->version);
        $request = app(NgrokSupervisorShutdownSignal::class)->pendingFor($stopped->version);
        $unchanged = $service->stop($stopped->version);

        self::assertTrue($stopped->changed);
        self::assertFalse($unchanged->changed);
        self::assertSame('widget_stop', $request['reason']);
        self::assertSame($stopped->version->toArray(), $request['target_version']);
    }
}

final class RecordingSpawner implements NgrokSupervisorSpawner
{
    /** @var list<array{incarnation: string, revision: int}> */
    public array $generations = [];

    public function spawn(NgrokSettingsVersion $generation): void
    {
        $this->generations[] = $generation->toArray();
    }
}
