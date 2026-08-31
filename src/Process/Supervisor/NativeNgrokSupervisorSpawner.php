<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRealtimeStarter;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokSupervisorSpawner;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final readonly class NativeNgrokSupervisorSpawner implements NgrokSupervisorSpawner
{
    public function __construct(
        private DetachedArtisanCommandLauncher $commands,
        private NgrokRealtimeStarter $realtime,
    ) {}

    public function spawn(NgrokSettingsVersion $generation): void
    {
        $this->realtime->ensureRunning();
        $this->commands->launch(
            (string) config('ngrok-server.commands.supervisor', 'ngrok-server:ngrok-supervisor'),
            ['--wait-for-runtime', '--desired-incarnation='.$generation->incarnation, '--desired-revision='.$generation->revision],
        );
    }
}
