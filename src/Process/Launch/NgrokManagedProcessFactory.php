<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Process\Windows\WindowsJobObjectProcessFactory;
use Symfony\Component\Process\Process;

readonly class NgrokManagedProcessFactory
{
    public function __construct(private WindowsJobObjectProcessFactory $windows) {}

    /** @param list<string> $command @param array<string, string|false> $environment */
    public function start(
        array $command,
        array $environment,
        NgrokProcessOwnership $ownership,
        ?string $workingDirectory = null,
    ): NgrokProcessHandle {
        if (PHP_OS_FAMILY === 'Windows') {
            if (! $ownership->isHandleBound()) {
                throw new RuntimeException('Windows ngrok requires handle-bound Job Object ownership.');
            }

            return $this->windows->start($command, $environment, $ownership, $workingDirectory);
        }
        if ($ownership->type !== NgrokProcessOwnership::POSIX_PROCESS || $ownership->id !== null) {
            throw new RuntimeException('The ngrok process ownership does not match the current platform.');
        }

        $process = new Process($command, $workingDirectory, $environment);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->disableOutput();
        $process->start();

        return new SymfonyNgrokProcessHandle($process);
    }
}
