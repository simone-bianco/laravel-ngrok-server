<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

interface NgrokProcessHandle
{
    public function isRunning(): bool;

    public function getPid(): ?int;

    public function getExitCode(): ?int;

    public function stop(float $timeout = 10, ?int $signal = null): ?int;

    public function getTimeout(): ?float;

    public function getIdleTimeout(): ?float;

    public function isOutputDisabled(): bool;

    public function getCommandLine(): string;
}
