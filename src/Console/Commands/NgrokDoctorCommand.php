<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Console\Commands;

use Illuminate\Console\Command;
use SimoneBianco\LaravelNgrokServer\Integration\NgrokHostIntegrationValidator;

final class NgrokDoctorCommand extends Command
{
    protected $signature;

    protected $description = 'Validate the host adapters required by Laravel Ngrok Server';

    public function __construct()
    {
        $this->signature = (string) config('ngrok-server.commands.doctor', 'ngrok-server:doctor');
        parent::__construct();
    }

    public function handle(NgrokHostIntegrationValidator $validator): int
    {
        try {
            $validator->assertReady();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Laravel Ngrok Server host integration is ready.');

        return self::SUCCESS;
    }
}
