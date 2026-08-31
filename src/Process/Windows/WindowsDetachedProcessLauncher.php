<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Windows;

use Closure;
use RuntimeException;

final readonly class WindowsDetachedProcessLauncher
{
    /** @var Closure(list<string>, string, array<string, string|false>, bool): (array{pid: int, running: bool}|false) */
    private Closure $processLauncher;

    /** @param (Closure(list<string>, string, array<string, string|false>, bool): (array{pid: int, running: bool}|false))|null $processLauncher */
    public function __construct(?Closure $processLauncher = null)
    {
        $this->processLauncher = $processLauncher
            ?? static function (array $command, string $directory, array $environment, bool $showConsole): array|false {
                $process = proc_open(
                    $command,
                    [
                        0 => ['file', 'NUL', 'r'],
                        1 => ['file', 'NUL', 'a'],
                        2 => ['file', 'NUL', 'a'],
                    ],
                    $pipes,
                    $directory,
                    $environment,
                    [
                        'bypass_shell' => true,
                        'create_process_group' => true,
                        'create_new_console' => $showConsole,
                    ],
                );
                if (! is_resource($process)) {
                    return false;
                }

                $status = proc_get_status($process);
                unset($process);

                return [
                    'pid' => (int) ($status['pid'] ?? 0),
                    'running' => ($status['running'] ?? false) === true,
                ];
            };
    }

    /** @param list<string> $command @param array<string, string|false> $environment */
    public function launch(array $command, string $directory, array $environment, bool $showConsole = false): void
    {
        $status = ($this->processLauncher)(
            $command,
            $directory,
            $environment,
            $showConsole,
        );

        if (! is_array($status) || $status['pid'] <= 0 || ! $status['running']) {
            throw new RuntimeException('The detached Windows supervisor did not remain running after launch.');
        }
    }
}
