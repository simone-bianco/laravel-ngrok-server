<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Windows;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;

class WindowsJobObjectInspector
{
    private const int JOB_OBJECT_QUERY = 0x0004;

    private const int ERROR_FILE_NOT_FOUND = 2;

    private const int ERROR_INVALID_HANDLE = 6;

    private mixed $ffi = null;

    public function exists(NgrokProcessOwnership $ownership): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Windows Job Object inspection is only available on Windows.');
        }
        if (! $ownership->isHandleBound() || ! is_string($ownership->id) || $ownership->id === '') {
            throw new RuntimeException('A handle-bound Windows Job Object identity is required.');
        }

        $ffi = $this->ffi();
        $handle = $ffi->OpenJobObjectW(
            self::JOB_OBJECT_QUERY,
            0,
            $this->wideString($ffi, $ownership->id),
        );
        if ($handle === null || \FFI::isNull($handle)) {
            $error = (int) $ffi->GetLastError();
            if (in_array($error, [self::ERROR_FILE_NOT_FOUND, self::ERROR_INVALID_HANDLE], true)) {
                return false;
            }

            throw new RuntimeException("Unable to inspect the ngrok Windows Job Object (error [$error]).");
        }

        $ffi->CloseHandle($handle);

        return true;
    }

    private function ffi(): mixed
    {
        if ($this->ffi !== null) {
            return $this->ffi;
        }
        if (! extension_loaded('ffi') || ! class_exists(\FFI::class)) {
            throw new RuntimeException('Windows Job Object inspection requires PHP FFI.');
        }

        try {
            $this->ffi = \FFI::cdef(<<<'CDEF'
typedef void* HANDLE;
typedef unsigned long DWORD;
typedef int BOOL;
typedef unsigned short WCHAR;
HANDLE OpenJobObjectW(DWORD desired_access, BOOL inherit_handle, const WCHAR* name);
BOOL CloseHandle(HANDLE handle);
DWORD GetLastError(void);
CDEF, 'kernel32.dll');
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to load the Windows Job Object inspection API.', previous: $exception);
        }

        return $this->ffi;
    }

    private function wideString(mixed $ffi, string $value): mixed
    {
        $bytes = mb_convert_encoding($value."\0", 'UTF-16LE', 'UTF-8');
        $buffer = $ffi->new('WCHAR['.intdiv(strlen($bytes), 2).']');
        \FFI::memcpy($buffer, $bytes, strlen($bytes));

        return $buffer;
    }
}
