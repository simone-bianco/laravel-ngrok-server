<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Windows;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;

final class WindowsJobObjectProcessFactory
{
    private const int JOB_OBJECT_EXTENDED_LIMIT_INFORMATION = 9;

    private const int JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x00002000;

    private const int PROC_THREAD_ATTRIBUTE_JOB_LIST = 0x0002000D;

    private const int CREATE_SUSPENDED = 0x00000004;

    private const int CREATE_UNICODE_ENVIRONMENT = 0x00000400;

    private const int EXTENDED_STARTUPINFO_PRESENT = 0x00080000;

    private const int CREATE_NO_WINDOW = 0x08000000;

    private const int RESUME_FAILED = 0xFFFFFFFF;

    private const int ERROR_ALREADY_EXISTS = 183;

    private mixed $ffi = null;

    /** @param list<string> $command @param array<string, string|false> $environment */
    public function start(
        array $command,
        array $environment,
        NgrokProcessOwnership $ownership,
        ?string $workingDirectory = null,
    ): WindowsJobObjectProcess {
        if (PHP_OS_FAMILY !== 'Windows' || PHP_INT_SIZE !== 8) {
            throw new RuntimeException('The Windows ngrok Job Object launcher requires 64-bit Windows.');
        }
        if ($command === [] || ! is_string($command[0]) || trim($command[0]) === '') {
            throw new RuntimeException('A Windows ngrok executable is required.');
        }
        if (! $ownership->isHandleBound() || ! is_string($ownership->id) || $ownership->id === '') {
            throw new RuntimeException('A deterministic Windows Job Object identity is required.');
        }

        $ffi = $this->ffi();
        $job = null;
        $process = null;
        $thread = null;
        $attributeListPointer = null;
        try {
            $jobName = $ownership->id;
            $job = $ffi->CreateJobObjectW(null, $this->wideString($ffi, $jobName));
            if (\FFI::isNull($job)) {
                throw $this->windowsError($ffi, 'Unable to create the ngrok Windows Job Object');
            }
            if ((int) $ffi->GetLastError() === self::ERROR_ALREADY_EXISTS) {
                throw new RuntimeException('The ngrok Windows Job Object already exists for this spawn attempt.');
            }

            $limits = $ffi->new('JOBOBJECT_EXTENDED_LIMIT_INFORMATION');
            $limits->BasicLimitInformation->LimitFlags = self::JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
            if ((int) $ffi->SetInformationJobObject(
                $job,
                self::JOB_OBJECT_EXTENDED_LIMIT_INFORMATION,
                \FFI::addr($limits),
                \FFI::sizeof($limits),
            ) === 0) {
                throw $this->windowsError($ffi, 'Unable to configure the ngrok Windows Job Object');
            }

            $attributeListSize = $ffi->new('SIZE_T');
            $ffi->InitializeProcThreadAttributeList(null, 1, 0, \FFI::addr($attributeListSize));
            if ((int) $attributeListSize->cdata <= 0) {
                throw $this->windowsError($ffi, 'Unable to size the ngrok process attribute list');
            }
            $attributeList = $ffi->new('char['.(int) $attributeListSize->cdata.']');
            $attributeListPointer = \FFI::addr($attributeList[0]);
            if ((int) $ffi->InitializeProcThreadAttributeList(
                $attributeListPointer,
                1,
                0,
                \FFI::addr($attributeListSize),
            ) === 0) {
                throw $this->windowsError($ffi, 'Unable to initialize the ngrok process attribute list');
            }

            $jobList = $ffi->new('HANDLE[1]');
            \FFI::memcpy($jobList, \FFI::addr($job), \FFI::sizeof($job));
            if ((int) $ffi->UpdateProcThreadAttribute(
                $attributeListPointer,
                0,
                self::PROC_THREAD_ATTRIBUTE_JOB_LIST,
                $jobList,
                \FFI::sizeof($jobList),
                null,
                null,
            ) === 0) {
                throw $this->windowsError($ffi, 'Unable to bind the ngrok process to its Job Object at creation');
            }

            $startup = $ffi->new('STARTUPINFOEXW');
            $startup->StartupInfo->cb = \FFI::sizeof($startup);
            $startup->lpAttributeList = $attributeListPointer;
            $information = $ffi->new('PROCESS_INFORMATION');
            $commandLine = implode(' ', array_map($this->quoteArgument(...), $command));
            $commandLineBuffer = $this->wideString($ffi, $commandLine);
            $applicationBuffer = $this->wideString($ffi, $command[0]);
            $directoryBuffer = $this->wideString($ffi, $workingDirectory ?? getcwd() ?: '.');
            $environmentBuffer = $this->environmentBlock($ffi, $environment);
            $flags = self::CREATE_SUSPENDED
                | self::CREATE_UNICODE_ENVIRONMENT
                | self::EXTENDED_STARTUPINFO_PRESENT
                | self::CREATE_NO_WINDOW;

            if ((int) $ffi->CreateProcessW(
                $applicationBuffer,
                $commandLineBuffer,
                null,
                null,
                0,
                $flags,
                $environmentBuffer,
                $directoryBuffer,
                \FFI::addr($startup->StartupInfo),
                \FFI::addr($information),
            ) === 0) {
                throw $this->windowsError($ffi, 'Unable to create the owned ngrok process');
            }
            $process = $information->hProcess;
            $thread = $information->hThread;
            $pid = (int) $information->dwProcessId;

            if ((int) $ffi->ResumeThread($thread) === self::RESUME_FAILED) {
                throw $this->windowsError($ffi, 'Unable to resume the owned ngrok process');
            }
            $ffi->CloseHandle($thread);
            $thread = null;
            $ffi->DeleteProcThreadAttributeList($attributeListPointer);
            $attributeListPointer = null;

            return new WindowsJobObjectProcess($ffi, $job, $process, $pid, $commandLine);
        } catch (\Throwable $exception) {
            if ($process !== null && $job !== null) {
                $ffi->TerminateJobObject($job, 1);
            }
            if ($thread !== null) {
                $ffi->CloseHandle($thread);
            }
            if ($process !== null) {
                $ffi->CloseHandle($process);
            }
            if ($attributeListPointer !== null) {
                $ffi->DeleteProcThreadAttributeList($attributeListPointer);
            }
            if ($job !== null) {
                $ffi->CloseHandle($job);
            }

            throw $exception;
        }
    }

    private function ffi(): mixed
    {
        if ($this->ffi !== null) {
            return $this->ffi;
        }
        if (! extension_loaded('ffi') || ! class_exists(\FFI::class)) {
            throw new RuntimeException('Windows ngrok process ownership requires PHP FFI.');
        }

        try {
            $this->ffi = \FFI::cdef(<<<'CDEF'
typedef void* HANDLE;
typedef unsigned long DWORD;
typedef int BOOL;
typedef unsigned short WCHAR;
typedef unsigned short WORD;
typedef unsigned long long SIZE_T;
typedef unsigned long long ULONG_PTR;
typedef long long LONGLONG;
typedef struct { LONGLONG QuadPart; } LARGE_INTEGER;
typedef struct {
    DWORD cb; WCHAR* lpReserved; WCHAR* lpDesktop; WCHAR* lpTitle;
    DWORD dwX; DWORD dwY; DWORD dwXSize; DWORD dwYSize;
    DWORD dwXCountChars; DWORD dwYCountChars; DWORD dwFillAttribute;
    DWORD dwFlags; WORD wShowWindow; WORD cbReserved2;
    unsigned char* lpReserved2; HANDLE hStdInput; HANDLE hStdOutput; HANDLE hStdError;
} STARTUPINFOW;
typedef struct { STARTUPINFOW StartupInfo; void* lpAttributeList; } STARTUPINFOEXW;
typedef struct { HANDLE hProcess; HANDLE hThread; DWORD dwProcessId; DWORD dwThreadId; } PROCESS_INFORMATION;
typedef struct {
    LARGE_INTEGER PerProcessUserTimeLimit; LARGE_INTEGER PerJobUserTimeLimit;
    DWORD LimitFlags; SIZE_T MinimumWorkingSetSize; SIZE_T MaximumWorkingSetSize;
    DWORD ActiveProcessLimit; ULONG_PTR Affinity; DWORD PriorityClass; DWORD SchedulingClass;
} JOBOBJECT_BASIC_LIMIT_INFORMATION;
typedef struct {
    unsigned long long ReadOperationCount; unsigned long long WriteOperationCount;
    unsigned long long OtherOperationCount; unsigned long long ReadTransferCount;
    unsigned long long WriteTransferCount; unsigned long long OtherTransferCount;
} IO_COUNTERS;
typedef struct {
    JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation; IO_COUNTERS IoInfo;
    SIZE_T ProcessMemoryLimit; SIZE_T JobMemoryLimit;
    SIZE_T PeakProcessMemoryUsed; SIZE_T PeakJobMemoryUsed;
} JOBOBJECT_EXTENDED_LIMIT_INFORMATION;
HANDLE CreateJobObjectW(void*, const WCHAR*);
BOOL SetInformationJobObject(HANDLE, int, void*, DWORD);
BOOL InitializeProcThreadAttributeList(void*, DWORD, DWORD, SIZE_T*);
BOOL UpdateProcThreadAttribute(void*, DWORD, ULONG_PTR, void*, SIZE_T, void*, SIZE_T*);
void DeleteProcThreadAttributeList(void*);
BOOL CreateProcessW(const WCHAR*, WCHAR*, void*, void*, BOOL, DWORD, void*, const WCHAR*, STARTUPINFOW*, PROCESS_INFORMATION*);
DWORD ResumeThread(HANDLE);
BOOL TerminateJobObject(HANDLE, unsigned int);
DWORD WaitForSingleObject(HANDLE, DWORD);
BOOL GetExitCodeProcess(HANDLE, DWORD*);
BOOL CloseHandle(HANDLE);
DWORD GetLastError(void);
CDEF, 'kernel32.dll');
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to load the Windows Job Object process API.', previous: $exception);
        }

        return $this->ffi;
    }

    /** @param array<string, string|false> $environment */
    private function environmentBlock(mixed $ffi, array $environment): mixed
    {
        $visible = [];
        foreach ($environment as $key => $value) {
            if (! is_string($key) || str_contains($key, "\0") || str_contains($key, '=')) {
                continue;
            }
            $normalized = strtolower($key);
            if ($value === false) {
                unset($visible[$normalized]);

                continue;
            }
            if (str_contains($value, "\0")) {
                throw new RuntimeException('The ngrok child environment contains an invalid NUL byte.');
            }
            $visible[$normalized] = [$key, $value];
        }
        uasort($visible, static fn (array $left, array $right): int => strcasecmp($left[0], $right[0]));
        $entries = array_map(static fn (array $entry): string => $entry[0].'='.$entry[1], $visible);

        return $this->wideString($ffi, implode("\0", $entries)."\0");
    }

    private function wideString(mixed $ffi, string $value): mixed
    {
        $bytes = mb_convert_encoding($value."\0", 'UTF-16LE', 'UTF-8');
        $buffer = $ffi->new('WCHAR['.intdiv(strlen($bytes), 2).']');
        \FFI::memcpy($buffer, $bytes, strlen($bytes));

        return $buffer;
    }

    private function quoteArgument(string $argument): string
    {
        if ($argument !== '' && preg_match('/[\s"]/', $argument) !== 1) {
            return $argument;
        }

        $quoted = '"';
        $slashes = 0;
        foreach (str_split($argument) as $character) {
            if ($character === '\\') {
                $slashes++;

                continue;
            }
            if ($character === '"') {
                $quoted .= str_repeat('\\', $slashes * 2 + 1).'"';
                $slashes = 0;

                continue;
            }
            $quoted .= str_repeat('\\', $slashes).$character;
            $slashes = 0;
        }

        return $quoted.str_repeat('\\', $slashes * 2).'"';
    }

    private function windowsError(mixed $ffi, string $message): RuntimeException
    {
        return new RuntimeException($message.' (Windows error ['.(int) $ffi->GetLastError().']).');
    }
}
