<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

final readonly class NgrokProcessLauncher
{
    public function __construct(private NgrokManagedProcessFactory $processes) {}

    public function start(NgrokLaunchPreparation $launch): OwnedNgrokProcess
    {
        $metadata = json_encode([
            'local_gpt_endpoint_instance_id' => $launch->endpointInstanceId,
            'local_gpt_supervisor_instance_id' => $launch->supervisorInstanceId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $command = [
            $launch->binary->path,
            'http',
            $launch->upstream->value(),
            '--config', $launch->configPath,
            '--name', $launch->endpointInstanceId,
            '--metadata', $metadata,
            '--url', $launch->origin->value(),
            '--host-header', $launch->upstreamHostHeader,
            '--inspect=false',
            '--pooling-enabled=false',
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
