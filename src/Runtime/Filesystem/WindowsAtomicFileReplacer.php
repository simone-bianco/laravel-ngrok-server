<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Filesystem;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final class WindowsAtomicFileReplacer
{
    private const int MOVEFILE_REPLACE_EXISTING = 0x00000001;

    private const int MOVEFILE_WRITE_THROUGH = 0x00000008;

    private const int MAX_ATTEMPTS = 8;

    private const int MAX_RETRY_DELAY_MICROSECONDS = 100_000;

    /** @var list<int> */
    private const array RETRYABLE_ERRORS = [5, 32, 33];

    private mixed $ffi = null;

    /** @var Closure(): mixed */
    private Closure $ffiFactory;

    /** @var Closure(string, string): void */
    private Closure $helperReplace;

    /**
     * @param  (Closure(): mixed)|null  $ffiFactory
     * @param  (Closure(string, string): void)|null  $helperReplace
     */
    public function __construct(?Closure $ffiFactory = null, ?Closure $helperReplace = null)
    {
        $this->ffiFactory = $ffiFactory ?? $this->loadFfi(...);
        $this->helperReplace = $helperReplace ?? $this->replaceWithPhpCli(...);
    }

    public function replace(string $source, string $destination): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('The Windows atomic file replacer is only available on Windows.');
        }

        try {
            $ffi = $this->ffi();
        } catch (RuntimeException) {
            ($this->helperReplace)($source, $destination);

            return;
        }
        $sourcePath = $this->widePath($ffi, $source);
        $destinationPath = $this->widePath($ffi, $destination);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $moved = $ffi->MoveFileExW(
                $sourcePath,
                $destinationPath,
                self::MOVEFILE_REPLACE_EXISTING | self::MOVEFILE_WRITE_THROUGH,
            );
            if ($moved !== 0) {
                return;
            }

            $error = (int) $ffi->GetLastError();
            if (! in_array($error, self::RETRYABLE_ERRORS, true) || $attempt === self::MAX_ATTEMPTS) {
                throw new RuntimeException("Unable to atomically replace the Windows runtime file (error [$error]).");
            }

            usleep(min(self::MAX_RETRY_DELAY_MICROSECONDS, 10_000 * (2 ** ($attempt - 1))));
        }
    }

    private function ffi(): mixed
    {
        if ($this->ffi !== null) {
            return $this->ffi;
        }

        try {
            $this->ffi = ($this->ffiFactory)();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to load the Windows atomic file replacement API.', previous: $exception);
        }

        return $this->ffi;
    }

    private function loadFfi(): mixed
    {
        if (! extension_loaded('ffi') || ! class_exists(\FFI::class)) {
            throw new RuntimeException('Windows atomic runtime files require the PHP FFI extension.');
        }

        return \FFI::cdef(<<<'CDEF'
typedef unsigned short WCHAR;
typedef int BOOL;
typedef unsigned long DWORD;
BOOL MoveFileExW(const WCHAR *existing_file, const WCHAR *new_file, DWORD flags);
DWORD GetLastError(void);
CDEF, 'kernel32.dll');
    }

    private function replaceWithPhpCli(string $source, string $destination): void
    {
        $process = new Process([
            $this->phpWinBinary(),
            '-d',
            'ffi.enable=true',
            '-r',
            <<<'PHP'
$source = $argv[1] ?? '';
$destination = $argv[2] ?? '';
if ($source === '' || $destination === '') {
    exit(64);
}
try {
    $ffi = FFI::cdef(<<<'CDEF'
typedef unsigned short WCHAR;
typedef int BOOL;
typedef unsigned long DWORD;
BOOL MoveFileExW(const WCHAR *existing_file, const WCHAR *new_file, DWORD flags);
DWORD GetLastError(void);
CDEF, 'kernel32.dll');
    $wide = static function (string $path) use ($ffi): mixed {
        $bytes = mb_convert_encoding(str_replace('/', '\\', $path)."\0", 'UTF-16LE', 'UTF-8');
        $buffer = $ffi->new('WCHAR['.intdiv(strlen($bytes), 2).']');
        FFI::memcpy($buffer, $bytes, strlen($bytes));

        return $buffer;
    };
    $sourcePath = $wide($source);
    $destinationPath = $wide($destination);
    for ($attempt = 1; $attempt <= 8; $attempt++) {
        if ($ffi->MoveFileExW($sourcePath, $destinationPath, 0x00000001 | 0x00000008) !== 0) {
            exit(0);
        }
        $error = (int) $ffi->GetLastError();
        if (! in_array($error, [5, 32, 33], true) || $attempt === 8) {
            exit(70);
        }
        usleep(min(100_000, 10_000 * (2 ** ($attempt - 1))));
    }
} catch (Throwable) {
    exit(71);
}
exit(72);
PHP,
            $source,
            $destination,
        ], base_path());
        $process->setTimeout(5);

        $exit = $process->run();
        if ($exit !== 0) {
            throw new RuntimeException("Unable to atomically replace the Windows runtime file through the PHP CLI helper (exit [$exit]).");
        }
    }

    private function phpWinBinary(): string
    {
        $configured = trim((string) config('ngrok-server.supervisor_php_binary', ''));
        $candidate = $configured !== '' ? $configured : PHP_BINARY;
        if (strtolower(basename($candidate)) === 'php-cgi.exe') {
            $candidate = dirname($candidate).DIRECTORY_SEPARATOR.'php.exe';
        }
        $cli = realpath($candidate);
        $resolved = is_string($cli)
            ? realpath(dirname($cli).DIRECTORY_SEPARATOR.'php-win.exe')
            : false;
        if (! is_string($resolved) || ! is_file($resolved) || strtolower(basename($resolved)) !== 'php-win.exe') {
            throw new RuntimeException('The console-free PHP Windows binary is required for atomic runtime file replacement.');
        }

        return $resolved;
    }

    private function widePath(mixed $ffi, string $path): mixed
    {
        $normalized = str_replace('/', '\\', $path);
        $bytes = mb_convert_encoding($normalized."\0", 'UTF-16LE', 'UTF-8');
        $units = intdiv(strlen($bytes), 2);
        $buffer = $ffi->new("WCHAR[$units]");
        \FFI::memcpy($buffer, $bytes, strlen($bytes));

        return $buffer;
    }
}
