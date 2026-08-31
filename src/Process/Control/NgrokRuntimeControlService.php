<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Control;

use SimoneBianco\LaravelNgrokServer\Contracts\NgrokSupervisorSpawner;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDesiredStateStore;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorShutdownSignal;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokDesiredStateMutation;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final readonly class NgrokRuntimeControlService
{
    public function __construct(
        private NgrokDesiredStateStore $desired,
        private NgrokSupervisorSpawner $spawner,
        private NgrokSupervisorShutdownSignal $shutdownSignal,
    ) {}

    public function start(NgrokSettingsVersion $expected): NgrokDesiredStateMutation
    {
        $mutation = $this->desired->set(true, $expected, requireSpawnGate: true);
        if ($mutation->changed) {
            $this->spawner->spawn($mutation->version);
        }

        return $mutation;
    }

    public function stop(NgrokSettingsVersion $expected): NgrokDesiredStateMutation
    {
        $mutation = $this->desired->set(false, $expected);
        if ($mutation->changed) {
            $this->shutdownSignal->request('widget_stop', $mutation->version);
        }

        return $mutation;
    }
}
