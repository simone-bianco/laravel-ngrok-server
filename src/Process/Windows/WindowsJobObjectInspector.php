<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Windows;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use Symfony\Component\Process\Process;

class WindowsJobObjectInspector
{
    public function exists(NgrokProcessOwnership $ownership): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Windows Job Object inspection is only available on Windows.');
        }
        if (! $ownership->isHandleBound() || ! is_string($ownership->id) || $ownership->id === '') {
            throw new RuntimeException('A handle-bound Windows Job Object identity is required.');
        }

        // Native inspection runs in the same bounded, windowless helper for CLI and web callers.
        // Herd's web SAPI cannot call FFI::cdef when ffi.enable=preload.
        $process = new Process([
            $this->phpWinBinary(), '-d', 'ffi.enable=true',
            dirname(__DIR__, 3).'/resources/windows/job-object-exists.php', $ownership->id,
        ], base_path());
        $process->setTimeout(5);

        return match ($process->run()) {
            0 => true,
            3 => false,
            default => throw new RuntimeException('Unable to verify the ngrok Windows Job Object through the native inspection helper.'),
        };
    }

    private function phpWinBinary(): string
    {
        $configured = trim((string) config('ngrok-server.supervisor_php_binary', ''));
        $candidate = $configured !== '' ? $configured : PHP_BINARY;
        $cli = realpath($candidate);
        $resolved = is_string($cli) ? realpath(dirname($cli).DIRECTORY_SEPARATOR.'php-win.exe') : false;
        if (! is_string($resolved) || ! is_file($resolved) || strtolower(basename($resolved)) !== 'php-win.exe') {
            throw new RuntimeException('The console-free PHP Windows binary is required for native Job Object inspection.');
        }

        return $resolved;
    }
}
