<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class SymfonyNgrokProcessHandle implements NgrokProcessHandle
{
    public function __construct(private Process $process) {}

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function getPid(): ?int
    {
        return $this->process->getPid();
    }

    public function getExitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new RuntimeException('PID-based Symfony process termination is disabled for ngrok on Windows.');
        }

        return $this->process->stop($timeout, $signal);
    }

    public function getTimeout(): ?float
    {
        return $this->process->getTimeout();
    }

    public function getIdleTimeout(): ?float
    {
        return $this->process->getIdleTimeout();
    }

    public function isOutputDisabled(): bool
    {
        return $this->process->isOutputDisabled();
    }

    public function getCommandLine(): string
    {
        return $this->process->getCommandLine();
    }
}
