<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Process\Windows\WindowsJobObjectInspector;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;

final class WindowsJobObjectInspectorTest extends TestCase
{
    public function test_windowless_helper_distinguishes_a_live_job_from_a_released_job(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows Job Object inspection requires Windows.');
        }
        $ffi = \FFI::cdef('typedef void* HANDLE; typedef unsigned short WCHAR; HANDLE CreateJobObjectW(void* attributes, const WCHAR* name); int CloseHandle(HANDLE handle);', 'kernel32.dll');
        $id = 'Local\\NgrokInspectorTest-'.bin2hex(random_bytes(16));
        $bytes = mb_convert_encoding($id."\0", 'UTF-16LE', 'UTF-8');
        $name = $ffi->new('WCHAR['.intdiv(strlen($bytes), 2).']');
        \FFI::memcpy($name, $bytes, strlen($bytes));
        $handle = $ffi->CreateJobObjectW(null, $name);
        self::assertFalse($handle === null || \FFI::isNull($handle));
        $ownership = new NgrokProcessOwnership(NgrokProcessOwnership::WINDOWS_JOB, $id);
        $inspector = new WindowsJobObjectInspector;
        try {
            self::assertTrue($inspector->exists($ownership));
        } finally {
            $ffi->CloseHandle($handle);
        }
        self::assertFalse($inspector->exists($ownership));
    }

    public function test_unavailable_helper_never_proves_absence(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows Job Object inspection requires Windows.');
        }
        config()->set('ngrok-server.supervisor_php_binary', 'Z:/missing-php/php.exe');
        $this->expectException(\RuntimeException::class);
        (new WindowsJobObjectInspector)->exists(new NgrokProcessOwnership(NgrokProcessOwnership::WINDOWS_JOB, 'Local\\missing-test'));
    }
}
