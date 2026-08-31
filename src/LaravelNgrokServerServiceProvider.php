<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer;

use Illuminate\Support\ServiceProvider;
use SimoneBianco\LaravelNgrokServer\Console\Commands\NgrokDoctorCommand;
use SimoneBianco\LaravelNgrokServer\Console\Commands\NgrokRecoverCommand;
use SimoneBianco\LaravelNgrokServer\Console\Commands\NgrokRuntimeBootstrapCommand;
use SimoneBianco\LaravelNgrokServer\Console\Commands\NgrokShutdownCommand;
use SimoneBianco\LaravelNgrokServer\Console\Commands\NgrokSupervisorCommand;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokSupervisorSpawner;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Integration\NgrokHostIntegrationValidator;
use SimoneBianco\LaravelNgrokServer\Process\Control\NgrokRuntimeControlService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\AgentApiReachabilityProbe;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\HttpAgentApiReachabilityProbe;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NativeNgrokSupervisorSpawner;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokRuntimeRecoveryService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorLock;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorShutdownSignal;

final class LaravelNgrokServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ngrok-server.php', 'ngrok-server');
        $this->app->singleton(NgrokHostIntegrationValidator::class);
        $this->app->singleton(NgrokRuntimeLock::class, NgrokSupervisorLock::class);
        $this->app->singleton(NgrokSupervisorShutdownSignal::class);
        $this->app->singleton(AgentApiReachabilityProbe::class, HttpAgentApiReachabilityProbe::class);
        $this->app->singleton(NgrokSupervisorSpawner::class, NativeNgrokSupervisorSpawner::class);
        $this->app->singleton(NgrokRuntimeControlService::class);
        $this->app->singleton(NgrokRuntimeRecoveryService::class);
        $this->app->singleton(NgrokSupervisor::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ngrok-server.php' => config_path('ngrok-server.php'),
        ], 'ngrok-server-config');
        if ($this->app->runningInConsole()) {
            $this->commands([
                NgrokDoctorCommand::class,
                NgrokSupervisorCommand::class,
                NgrokRuntimeBootstrapCommand::class,
                NgrokRecoverCommand::class,
                NgrokShutdownCommand::class,
            ]);
        }
    }
}
