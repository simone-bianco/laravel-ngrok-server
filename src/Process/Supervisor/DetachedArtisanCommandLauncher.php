<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Windows\WindowsDetachedProcessLauncher;

final readonly class DetachedArtisanCommandLauncher
{
    public function __construct(private WindowsDetachedProcessLauncher $windows) {}

    /** @param list<string> $arguments */
    public function launch(string $command, array $arguments = []): void
    {
        $arguments = [$this->phpBinary(), $this->artisanPath(), $command, ...$arguments];

        if (PHP_OS_FAMILY === 'Windows') {
            $this->windows->launch($arguments, base_path(), $this->environment(), $this->showConsole());

            return;
        }

        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : '/bin/setsid';
        if (! is_executable($setsid)) {
            throw new RuntimeException('The detached ngrok supervisor requires an absolute setsid executable.');
        }

        $process = proc_open([$setsid, '--fork', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, base_path(), $this->environment(), ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to launch the detached ngrok supervisor.');
        }
        if (proc_close($process) !== 0) {
            throw new RuntimeException('The detached ngrok supervisor launcher exited unsuccessfully.');
        }
    }

    private function showConsole(): bool
    {
        return (bool) config('ngrok-server.supervisor.show_console', false);
    }

    private function phpBinary(): string
    {
        $configured = trim((string) config('ngrok-server.supervisor_php_binary', ''));
        $candidate = $configured !== '' ? $configured : PHP_BINARY;
        if (strtolower(basename($candidate)) === 'php-cgi.exe') {
            $candidate = dirname($candidate).DIRECTORY_SEPARATOR.'php.exe';
        }
        $cli = realpath($candidate);
        if (! is_string($cli) || ! is_file($cli) || ! in_array(strtolower(basename($cli)), ['php', 'php.exe'], true)) {
            throw new RuntimeException('A validated PHP CLI binary is required to launch the ngrok supervisor.');
        }
        if (PHP_OS_FAMILY !== 'Windows' || $this->showConsole()) {
            return $cli;
        }

        $windowless = realpath(dirname($cli).DIRECTORY_SEPARATOR.'php-win.exe');
        if (! is_string($windowless) || ! is_file($windowless) || strtolower(basename($windowless)) !== 'php-win.exe') {
            throw new RuntimeException('The console-free PHP Windows binary is required to launch the ngrok supervisor.');
        }

        return $windowless;
    }

    private function artisanPath(): string
    {
        $artisan = realpath(base_path('artisan'));
        if (! is_string($artisan) || ! is_file($artisan)) {
            throw new RuntimeException('The canonical Artisan entrypoint is unavailable.');
        }

        return $artisan;
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        $source = getenv();

        return is_array($source) ? array_filter($source, static fn (mixed $value, mixed $key): bool => is_string($key) && (is_string($value) || $value === false), ARRAY_FILTER_USE_BOTH) : [];
    }
}
