<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Support;

use SimoneBianco\LaravelNgrokServer\Contracts\NgrokRealtimeStarter;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDesiredStateStore;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokDirectStatusProjection;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeIdentityVerifier;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeLifecycleFence;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeRemoteAccessPolicy;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokDesiredStateMutation;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class FakeSettings implements NgrokRuntimeSettingsSnapshotProvider
{
    public function __construct(public NgrokSettingsSnapshot $snapshot) {}

    public function capture(int $attempts = 3): NgrokSettingsSnapshot
    {
        return $this->snapshot;
    }

    public function currentVersion(): NgrokSettingsVersion
    {
        return $this->snapshot->version;
    }
}

final class FakeLifecycleFence implements NgrokRuntimeLifecycleFence
{
    public int $calls = 0;

    public function synchronized(callable $callback): mixed
    {
        $this->calls++;

        return $callback();
    }
}

final class FakeDesiredStateStore implements NgrokDesiredStateStore
{
    public bool $requiredSpawnGate = false;

    public function __construct(private FakeSettings $settings) {}

    public function set(bool $enabled, NgrokSettingsVersion $expected, bool $requireSpawnGate = false): NgrokDesiredStateMutation
    {
        $this->requiredSpawnGate = $requireSpawnGate;
        if ($this->settings->snapshot->desiredEnabled === $enabled) {
            return new NgrokDesiredStateMutation($this->settings->snapshot->version, false);
        }
        $version = new NgrokSettingsVersion($expected->incarnation, $expected->revision + 1);
        $this->settings->snapshot = new NgrokSettingsSnapshot($version, $this->settings->snapshot->remoteAccessEnabled, $enabled, $this->settings->snapshot->externalOrigin, $this->settings->snapshot->authtoken, $this->settings->snapshot->authtokenSource);

        return new NgrokDesiredStateMutation($version, true);
    }
}

final class FakeIdentityVerifier implements NgrokRuntimeIdentityVerifier
{
    public function protocolVersion(): int
    {
        return 1;
    }

    public function encodeNonce(string $nonce): string
    {
        return rtrim(strtr(base64_encode($nonce), '+/', '-_'), '=');
    }

    public function verify(string $nonce, string $signature): bool
    {
        return $signature === 'valid';
    }
}

final class FakeRemotePolicy implements NgrokRuntimeRemoteAccessPolicy
{
    public function __construct(public bool $blocked = false) {}

    public function debugEnabled(): bool
    {
        return $this->blocked;
    }

    public function overrideEnabled(): bool
    {
        return false;
    }

    public function blocksRemoteAccess(): bool
    {
        return $this->blocked;
    }

    public function overrideActive(): bool
    {
        return false;
    }
}

final class FakeDirectStatusProjector implements NgrokDirectStatusProjection
{
    public function project(?NgrokSettingsSnapshot $settings): array
    {
        return ['state' => 'test'];
    }
}

final class FakeRealtimeStarter implements NgrokRealtimeStarter
{
    public int $starts = 0;

    public function ensureRunning(): void
    {
        $this->starts++;
    }
}
