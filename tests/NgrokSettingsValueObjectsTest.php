<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class NgrokSettingsValueObjectsTest extends TestCase
{
    public function test_version_compares_and_serializes_its_exact_identity(): void
    {
        $version = new NgrokSettingsVersion('00000000000000000000000000', 7);

        self::assertTrue($version->equals(new NgrokSettingsVersion('00000000000000000000000000', 7)));
        self::assertFalse($version->equals(new NgrokSettingsVersion('00000000000000000000000000', 8)));
        self::assertSame(['incarnation' => '00000000000000000000000000', 'revision' => 7], $version->toArray());
    }

    public function test_version_rejects_invalid_scope_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NgrokSettingsVersion('invalid', -1);
    }

    public function test_snapshot_compares_remote_values_without_comparing_its_version(): void
    {
        $first = new NgrokSettingsSnapshot(
            new NgrokSettingsVersion('00000000000000000000000000', 1),
            true,
            true,
            null,
            'secret',
            'database',
        );
        $sameValuesNewVersion = new NgrokSettingsSnapshot(
            new NgrokSettingsVersion('00000000000000000000000000', 2),
            true,
            true,
            null,
            'secret',
            'database',
        );

        self::assertTrue($first->remoteValuesEqual($sameValuesNewVersion));
    }
}
