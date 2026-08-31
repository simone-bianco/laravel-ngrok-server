<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokSettingsGeneration;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class NgrokSettingsGenerationTest extends TestCase
{
    public function test_fingerprint_contains_only_the_settings_version_and_never_secrets(): void
    {
        $generation = new NgrokSettingsGeneration;
        $version = new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 42);
        $first = new NgrokSettingsSnapshot($version, true, true, null, 'first-secret', 'database');
        $differentSecret = new NgrokSettingsSnapshot($version, false, false, null, 'second-secret', 'environment');

        $value = $generation->fingerprint($first);

        self::assertSame('sv1:01ARZ3NDEKTSV4RRFFQ69G5FAV:42', $value);
        self::assertSame($value, $generation->fingerprint($differentSecret));
        self::assertStringNotContainsString('first-secret', $value);
        self::assertStringNotContainsString('second-secret', $value);
        self::assertNotSame($value, $generation->fingerprint(new NgrokSettingsSnapshot(
            new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 43),
            true,
            true,
            null,
            'first-secret',
            'database',
        )));
    }
}
