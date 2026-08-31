<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Windows;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokProcessHandle;

final class WindowsJobObjectProcess implements NgrokProcessHandle
{
    private const int WAIT_OBJECT_0 = 0;

    private const int WAIT_TIMEOUT = 258;

    private const int TERMINATION_EXIT_CODE = 1;

    private mixed $jobHandle;

    private mixed $processHandle;

    private ?int $exitCode = null;

    private bool $closed = false;

    public function __construct(
        private readonly mixed $ffi,
        mixed $jobHandle,
        mixed $processHandle,
        private readonly int $pid,
        private readonly string $commandLine,
    ) {
        $this->jobHandle = $jobHandle;
        $this->processHandle = $processHandle;
    }

    public function __destruct()
    {
        $this->closeHandles();
    }

    public function isRunning(): bool
    {
        if ($this->closed || $this->processHandle === null) {
            return false;
        }

        $wait = (int) $this->ffi->WaitForSingleObject($this->processHandle, 0);
        if ($wait === self::WAIT_TIMEOUT) {
            return true;
        }
        if ($wait === self::WAIT_OBJECT_0) {
            $this->captureExitCode();

            return false;
        }

        throw new RuntimeException('Unable to query the owned Windows ngrok process handle.');
    }

    public function getPid(): ?int
    {
        return $this->isRunning() ? $this->pid : null;
    }

    public function getExitCode(): ?int
    {
        if ($this->isRunning()) {
            return null;
        }

        return $this->exitCode;
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        if ($this->closed) {
            return $this->exitCode;
        }
        if (! $this->isRunning()) {
            $this->closeHandles();

            return $this->exitCode;
        }

        $terminated = (int) $this->ffi->TerminateJobObject($this->jobHandle, self::TERMINATION_EXIT_CODE);
        if ($terminated === 0) {
            // KILL_ON_JOB_CLOSE was configured before the process was created.
            $this->closeJobHandle();
        }

        $milliseconds = max(1, min(30_000, (int) ceil($timeout * 1000)));
        $wait = (int) $this->ffi->WaitForSingleObject($this->processHandle, $milliseconds);
        if ($wait !== self::WAIT_OBJECT_0) {
            return null;
        }

        $this->captureExitCode();
        $this->closeHandles();

        return $this->exitCode;
    }

    public function getTimeout(): ?float
    {
        return null;
    }

    public function getIdleTimeout(): ?float
    {
        return null;
    }

    public function isOutputDisabled(): bool
    {
        return true;
    }

    public function getCommandLine(): string
    {
        return $this->commandLine;
    }

    private function captureExitCode(): void
    {
        if ($this->exitCode !== null || $this->processHandle === null) {
            return;
        }

        $code = $this->ffi->new('DWORD');
        if ((int) $this->ffi->GetExitCodeProcess($this->processHandle, \FFI::addr($code)) === 0) {
            throw new RuntimeException('Unable to read the owned Windows ngrok process exit code.');
        }
        $this->exitCode = (int) $code->cdata;
    }

    private function closeHandles(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closeJobHandle();
        if ($this->processHandle !== null) {
            $this->ffi->CloseHandle($this->processHandle);
            $this->processHandle = null;
        }
        $this->closed = true;
    }

    private function closeJobHandle(): void
    {
        if ($this->jobHandle === null) {
            return;
        }

        $this->ffi->CloseHandle($this->jobHandle);
        $this->jobHandle = null;
    }
}
