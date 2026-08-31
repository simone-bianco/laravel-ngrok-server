<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests;

use SimoneBianco\LaravelNgrokServer\Process\Windows\WindowsDetachedProcessLauncher;

require_once __DIR__.'/TestCase.php';

final class WindowsDetachedProcessLauncherTest extends TestCase
{
    public function test_it_uses_a_hidden_console_by_default(): void
    {
        $showConsole = null;
        $launcher = new WindowsDetachedProcessLauncher(
            static function (array $command, string $directory, array $environment, bool $visible) use (&$showConsole): array {
                $showConsole = $visible;

                return ['pid' => 1, 'running' => true];
            },
        );

        $launcher->launch(['php-win.exe', 'artisan'], 'C:\\project', []);

        self::assertFalse($showConsole);
    }

    public function test_it_passes_visible_console_selection_to_the_process_launcher(): void
    {
        $showConsole = null;
        $launcher = new WindowsDetachedProcessLauncher(
            static function (array $command, string $directory, array $environment, bool $visible) use (&$showConsole): array {
                $showConsole = $visible;

                return ['pid' => 1, 'running' => true];
            },
        );

        $launcher->launch(['php.exe', 'artisan'], 'C:\\project', [], true);

        self::assertTrue($showConsole);
    }

    public function test_it_rejects_a_launcher_that_does_not_leave_a_running_child(): void
    {
        $launcher = new WindowsDetachedProcessLauncher(
            static fn (): array => ['pid' => 0, 'running' => false],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not remain running');

        $launcher->launch(['php-win.exe', 'artisan'], 'C:\\project', []);
    }

    public function test_windows_child_survives_the_launcher_scope(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows detached launcher contract.');
        }

        $phpWin = realpath(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php-win.exe');
        self::assertIsString($phpWin);
        $marker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-detached-'.bin2hex(random_bytes(8)).'.txt';
        $script = sprintf('sleep(1); file_put_contents(%s, "ready");', var_export($marker, true));

        try {
            $startedAt = microtime(true);
            (new WindowsDetachedProcessLauncher)->launch([$phpWin, '-r', $script], sys_get_temp_dir(), []);
            self::assertLessThan(1.0, microtime(true) - $startedAt);

            $deadline = microtime(true) + 5;
            while (! is_file($marker) && microtime(true) < $deadline) {
                usleep(50_000);
            }

            self::assertFileExists($marker);
            self::assertSame('ready', file_get_contents($marker));
        } finally {
            @unlink($marker);
        }
    }
}
