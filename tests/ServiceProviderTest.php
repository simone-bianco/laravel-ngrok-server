<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests;

use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRealtimeStarter;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokSupervisorSpawner;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Integration\NgrokHostIntegrationValidator;
use SimoneBianco\LaravelNgrokServer\Process\Control\NgrokRuntimeControlService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokRuntimeRecoveryService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorLock;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\NgrokRuntimeIdentityProbe;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\RuntimeIdentityObservation;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\RuntimeIdentityProbe;
use SimoneBianco\LaravelNgrokServer\Runtime\Status\NgrokRuntimeStatusService;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeRealtimeStarter;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;

require_once __DIR__.'/TestCase.php';

final class ServiceProviderTest extends TestCase
{
    public function test_it_merges_hidden_console_defaults(): void
    {
        self::assertFalse(config('ngrok-server.supervisor.show_console'));
    }

    public function test_it_registers_portable_lifecycle_command_defaults(): void
    {
        self::assertSame('ngrok-server:doctor', config('ngrok-server.commands.doctor'));
        self::assertSame('ngrok-server:ngrok-supervisor', config('ngrok-server.commands.supervisor'));
        self::assertSame('ngrok-server:ngrok-bootstrap', config('ngrok-server.commands.bootstrap'));
        self::assertSame('ngrok-server:ngrok-recover', config('ngrok-server.commands.recover'));
        self::assertSame('ngrok-server:ngrok-shutdown', config('ngrok-server.commands.shutdown'));
    }

    public function test_it_binds_package_owned_services_and_console_commands(): void
    {
        self::assertInstanceOf(NgrokSupervisorLock::class, $this->app->make(NgrokRuntimeLock::class));
        self::assertTrue($this->app->bound(NgrokSupervisorSpawner::class));
        self::assertTrue($this->app->bound(NgrokRuntimeControlService::class));
        self::assertTrue($this->app->bound(NgrokRuntimeRecoveryService::class));
        self::assertTrue($this->app->bound(NgrokSupervisor::class));
        self::assertTrue($this->app->bound(NgrokHostIntegrationValidator::class));
        self::assertInstanceOf(RuntimeIdentityProbe::class, $this->app->make(NgrokRuntimeIdentityProbe::class));

        $this->artisan('list')
            ->expectsOutputToContain('ngrok-server:doctor')
            ->expectsOutputToContain('ngrok-server:ngrok-supervisor')
            ->expectsOutputToContain('ngrok-server:ngrok-bootstrap')
            ->expectsOutputToContain('ngrok-server:ngrok-recover')
            ->expectsOutputToContain('ngrok-server:ngrok-shutdown')
            ->assertSuccessful();
    }

    public function test_a_host_can_replace_the_runtime_identity_probe(): void
    {
        $probe = new class implements NgrokRuntimeIdentityProbe
        {
            public function observe(NgrokLocalUpstream $upstream, string $hostHeader = ''): RuntimeIdentityObservation
            {
                return RuntimeIdentityObservation::mismatch();
            }

            public function healthy(NgrokLocalUpstream $upstream, string $hostHeader = ''): bool
            {
                return false;
            }
        };
        $this->app->instance(NgrokRuntimeIdentityProbe::class, $probe);

        self::assertSame($probe, $this->app->make(NgrokRuntimeIdentityProbe::class));
    }

    public function test_doctor_reports_missing_host_adapters_actionably(): void
    {
        $this->app->offsetUnset(NgrokRealtimeStarter::class);

        self::assertSame([NgrokRealtimeStarter::class], app(NgrokHostIntegrationValidator::class)->missingAdapters());
        $this->artisan('ngrok-server:doctor')
            ->expectsOutputToContain(NgrokRealtimeStarter::class)
            ->assertFailed();
    }

    public function test_doctor_resolves_the_operational_package_surface_with_representative_adapters(): void
    {
        $this->app->instance(NgrokRealtimeStarter::class, new FakeRealtimeStarter);

        app(NgrokHostIntegrationValidator::class)->assertReady();
        self::assertInstanceOf(NgrokRuntimeControlService::class, app(NgrokRuntimeControlService::class));
        self::assertInstanceOf(NgrokRuntimeRecoveryService::class, app(NgrokRuntimeRecoveryService::class));
        self::assertInstanceOf(NgrokSupervisor::class, app(NgrokSupervisor::class));
        self::assertInstanceOf(NgrokRuntimeStatusService::class, app(NgrokRuntimeStatusService::class));
        $this->artisan('ngrok-server:doctor')
            ->expectsOutput('Laravel Ngrok Server host integration is ready.')
            ->assertSuccessful();
    }
}
