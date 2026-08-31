<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Console\Commands;

use Illuminate\Console\Command;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokRuntimeRecoveryService;

final class NgrokRecoverCommand extends Command
{
    protected $signature;

    protected $description = 'Recover a quiescent handle-bound ngrok attempt after an interrupted supervisor';

    public function __construct()
    {
        $this->signature = (string) config('ngrok-server.commands.recover', 'ngrok-server:ngrok-recover').' {--quiet-if-clean}';
        parent::__construct();
    }

    public function handle(NgrokRuntimeRecoveryService $recovery): int
    {
        try {
            $result = $recovery->recover();
        } catch (\Throwable $exception) {
            $this->error(mb_substr(preg_replace('/\s+/', ' ', trim($exception->getMessage())) ?: 'Ngrok recovery failed.', 0, 240));

            return self::FAILURE;
        }

        if ($result === 'recovered') {
            $this->info('The interrupted ngrok lifecycle attempt was verified quiescent and recovered.');
        } elseif (! $this->option('quiet-if-clean')) {
            $this->info('The ngrok lifecycle ledger is already clean.');
        }

        return self::SUCCESS;
    }
}
