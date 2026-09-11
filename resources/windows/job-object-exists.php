<?php

declare(strict_types=1);

// Exit 0: exists; 3: absent; all other exits: unverified. No process is adopted or stopped.
if (PHP_OS_FAMILY !== 'Windows' || ! isset($argv[1]) || $argv[1] === '') {
    exit(64);
}

try {
    $ffi = FFI::cdef(<<<'CDEF'
typedef void* HANDLE;
typedef unsigned long DWORD;
typedef int BOOL;
typedef unsigned short WCHAR;
HANDLE OpenJobObjectW(DWORD desired_access, BOOL inherit_handle, const WCHAR* name);
BOOL CloseHandle(HANDLE handle);
DWORD GetLastError(void);
CDEF, 'kernel32.dll');
    $bytes = mb_convert_encoding($argv[1]."\0", 'UTF-16LE', 'UTF-8');
    $name = $ffi->new('WCHAR['.intdiv(strlen($bytes), 2).']');
    FFI::memcpy($name, $bytes, strlen($bytes));
    $handle = $ffi->OpenJobObjectW(0x0004, 0, $name);
    if ($handle === null || FFI::isNull($handle)) {
        exit(in_array((int) $ffi->GetLastError(), [2, 6], true) ? 3 : 70);
    }
    exit($ffi->CloseHandle($handle) !== 0 ? 0 : 70);
} catch (Throwable) {
    exit(71);
}
