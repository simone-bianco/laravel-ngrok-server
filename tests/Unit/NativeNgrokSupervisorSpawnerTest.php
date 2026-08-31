<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use SimoneBianco\LaravelNgrokServer\Process\Supervisor\DetachedArtisanCommandLauncher;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NativeNgrokSupervisorSpawner;
use SimoneBianco\LaravelNgrokServer\Process\Windows\WindowsDetachedProcessLauncher;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeRealtimeStarter;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class NativeNgrokSupervisorSpawnerTest extends TestCase
{
    public function test_spawner_starts_realtime_before_launching_the_supervisor_command(): void
    {
        $starter = new FakeRealtimeStarter;
        $calls = [];
        $windows = new WindowsDetachedProcessLauncher(
            static function (array $command, string $directory, array $environment, bool $showConsole) use (&$calls): array {
                $calls[] = compact('command', 'directory', 'environment', 'showConsole');

                return ['pid' => 4321, 'running' => true];
            },
        );
        $commands = new DetachedArtisanCommandLauncher($windows);
        $spawner = new NativeNgrokSupervisorSpawner($commands, $starter);

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('absolute setsid executable');
        }

        $spawner->spawn(new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 42));

        self::assertSame(1, $starter->starts);
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertCount(1, $calls);
            self::assertSame('ngrok-server:ngrok-supervisor', $calls[0]['command'][2]);
            self::assertSame('--wait-for-runtime', $calls[0]['command'][3]);
            self::assertSame('--desired-incarnation=01ARZ3NDEKTSV4RRFFQ69G5FAV', $calls[0]['command'][4]);
            self::assertSame('--desired-revision=42', $calls[0]['command'][5]);
            self::assertFalse($calls[0]['showConsole']);
        }
    }
}
