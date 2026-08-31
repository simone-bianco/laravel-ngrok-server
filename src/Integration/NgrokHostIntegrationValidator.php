<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Integration;

use Illuminate\Contracts\Container\Container;
use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRealtimeStarter;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDesiredStateStore;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDirectStatusProjection;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeIdentityVerifier;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Process\Control\NgrokRuntimeControlService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokRuntimeRecoveryService;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Runtime\Status\NgrokRuntimeStatusService;
use Throwable;

final readonly class NgrokHostIntegrationValidator
{
    /** @var list<class-string> */
    private const array REQUIRED_ADAPTERS = [
        NgrokRealtimeStarter::class,
        NgrokRemoteAccessPolicy::class,
        NgrokDesiredStateStore::class,
        NgrokDirectStatusProjection::class,
        NgrokRuntimeIdentityVerifier::class,
        NgrokRuntimeLifecycleFence::class,
        NgrokRuntimeRemoteAccessPolicy::class,
        NgrokRuntimeSettingsSnapshotProvider::class,
    ];

    /** @var list<class-string> */
    private const array OPERATIONAL_SERVICES = [
        NgrokRuntimeControlService::class,
        NgrokRuntimeRecoveryService::class,
        NgrokSupervisor::class,
        NgrokRuntimeStatusService::class,
    ];

    public function __construct(private Container $container) {}

    /** @return list<class-string> */
    public function missingAdapters(): array
    {
        return array_values(array_filter(
            self::REQUIRED_ADAPTERS,
            fn (string $adapter): bool => ! $this->container->bound($adapter),
        ));
    }

    public function assertReady(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && ! extension_loaded('ffi')) {
            throw new RuntimeException('Laravel Ngrok Server requires the ext-ffi PHP extension for Windows Job Object process ownership.');
        }

        $missing = $this->missingAdapters();
        if ($missing !== []) {
            throw new RuntimeException('Laravel Ngrok Server requires host bindings for: '.implode(', ', $missing).'.');
        }

        foreach (self::OPERATIONAL_SERVICES as $service) {
            try {
                $this->container->make($service);
            } catch (Throwable $exception) {
                throw new RuntimeException("Laravel Ngrok Server cannot resolve [$service]: {$exception->getMessage()}", previous: $exception);
            }
        }
    }
}
