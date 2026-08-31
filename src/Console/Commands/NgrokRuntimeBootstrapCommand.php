<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Console\Commands;

use Illuminate\Console\Command;
use SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap\NgrokRuntimeBootstrapper;

final class NgrokRuntimeBootstrapCommand extends Command
{
    protected $signature;

    protected $description = 'Create the installation-scoped ngrok runtime manifest and clean lifecycle ledger exactly once';

    public function __construct()
    {
        $this->signature = (string) config('ngrok-server.commands.bootstrap', 'ngrok-server:ngrok-bootstrap').' {--confirm-new-runtime : Confirm this installation has no prior Local GPT ngrok lifecycle}';
        parent::__construct();
    }

    public function handle(NgrokRuntimeBootstrapper $bootstrapper): int
    {
        if (! $this->option('confirm-new-runtime')) {
            $this->error('Refusing bootstrap without --confirm-new-runtime. This command is create-once and never resets an existing runtime.');

            return self::INVALID;
        }
        try {
            $result = $bootstrapper->initialize();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Ngrok runtime initialized.');
        $this->line('Installation runtime id: '.$result['manifest']['installation_runtime_id']);

        return self::SUCCESS;
    }
}
