<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

final readonly class NgrokProcessLauncher
{
    public function __construct(private NgrokManagedProcessFactory $processes) {}

    public function start(NgrokLaunchPreparation $launch): OwnedNgrokProcess
    {
        $command = [
            $launch->binary->path,
            'start',
            $launch->endpointInstanceId,
            '--config', $launch->configPath,
        ];
        $process = $this->processes->start($command, $launch->environment, $launch->ownership);

        return new OwnedNgrokProcess($process, $launch);
    }

    public function stop(OwnedNgrokProcess $owned): bool
    {
        if (! $owned->process->isRunning()) {
            return true;
        }
        $owned->process->stop(max(1, (int) config('ngrok-server.process_stop_grace_seconds', 5)));

        return ! $owned->process->isRunning();
    }
}
