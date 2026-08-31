<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDesiredStateStore;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDirectStatusProjection;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeIdentityVerifier;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\LaravelNgrokServerServiceProvider;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeDesiredStateStore;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeDirectStatusProjector;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeIdentityVerifier;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeRemotePolicy;
use SimoneBianco\LaravelNgrokServer\Tests\Support\FakeSettings;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

abstract class TestCase extends OrchestraTestCase
{
    protected FakeSettings $ngrokSettings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ngrokSettings = new FakeSettings(new NgrokSettingsSnapshot(new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 0), true, false, null, null, 'none'));
        $this->app->instance(NgrokRuntimeSettingsSnapshotProvider::class, $this->ngrokSettings);
        $this->app->instance(NgrokRuntimeLifecycleFence::class, new FakeLifecycleFence);
        $this->app->instance(NgrokRuntimeIdentityVerifier::class, new FakeIdentityVerifier);
        $this->app->instance(NgrokRuntimeRemoteAccessPolicy::class, new FakeRemotePolicy);
        $this->app->instance(NgrokRemoteAccessPolicy::class, new class implements NgrokRemoteAccessPolicy
        {
            public function blocksRemoteAccess(): bool
            {
                return false;
            }
        });
        $this->app->instance(NgrokDirectStatusProjection::class, new FakeDirectStatusProjector);
        $this->app->instance(NgrokDesiredStateStore::class, new FakeDesiredStateStore($this->ngrokSettings));
    }

    protected function getPackageProviders($app): array
    {
        return [LaravelNgrokServerServiceProvider::class];
    }
}
