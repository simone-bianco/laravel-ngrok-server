<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests;

use ReflectionClass;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDirectStatusProjection;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeIdentityVerifier;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLock;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap\NgrokRuntimeBootstrapper;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\RuntimeIdentityProbe;
use SimoneBianco\LaravelNgrokServer\Runtime\Status\NgrokRuntimeStatusService;

require_once __DIR__.'/TestCase.php';

final class RuntimeContractsTest extends TestCase
{
    public function test_runtime_services_depend_only_on_package_contracts(): void
    {
        self::assertSame([
            NgrokRuntimeLock::class,
            NgrokRuntimeLifecycleFence::class,
            NgrokRuntimeSettingsSnapshotProvider::class,
        ], $this->constructorTypes(NgrokRuntimeBootstrapper::class, ['lock', 'fence', 'settings']));
        self::assertSame([
            NgrokRuntimeIdentityVerifier::class,
        ], $this->constructorTypes(RuntimeIdentityProbe::class, ['identity']));
        self::assertSame([
            NgrokRuntimeSettingsSnapshotProvider::class,
            NgrokRuntimeRemoteAccessPolicy::class,
            NgrokDirectStatusProjection::class,
        ], $this->constructorTypes(NgrokRuntimeStatusService::class, ['settings', 'debugPolicy', 'directStatus']));
    }

    /** @param class-string $class @param list<string> $parameters @return list<class-string> */
    private function constructorTypes(string $class, array $parameters): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor);

        $types = [];
        foreach ($constructor->getParameters() as $constructorParameter) {
            $types[$constructorParameter->getName()] = $constructorParameter->getType()?->getName();
        }

        return array_map(
            static fn (string $parameter): string => $types[$parameter]
                ?? throw new \RuntimeException("Missing type for [$parameter]."),
            $parameters,
        );
    }
}
