<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Console\Commands;

use Illuminate\Console\Command;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorShutdownSignal;

final class NgrokShutdownCommand extends Command
{
    protected $signature;

    protected $description = 'Request a graceful shutdown from the installation ngrok supervisor';

    public function __construct()
    {
        $this->signature = (string) config('ngrok-server.commands.shutdown', 'ngrok-server:ngrok-shutdown').' {--reason=launcher_shutdown}';
        parent::__construct();
    }

    public function handle(NgrokSupervisorShutdownSignal $signal): int
    {
        $signal->request((string) $this->option('reason'));
        $this->info('Ngrok supervisor shutdown requested.');

        return self::SUCCESS;
    }
}
