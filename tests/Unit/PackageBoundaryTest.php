<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokSettingsGeneration;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class PackageBoundaryTest extends TestCase
{
    public function test_source_has_no_host_application_or_settings_package_imports(): void
    {
        $source = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            self::assertStringNotContainsString('App\\', $contents, $file->getPathname());
            self::assertStringNotContainsString('Tests\\', $contents, $file->getPathname());
            self::assertStringNotContainsString('SimoneBianco\\LaravelSettings', $contents, $file->getPathname());
        }
    }

    public function test_generation_uses_only_the_strict_package_version_boundary(): void
    {
        $version = new NgrokSettingsVersion('00000000000000000000000000', 42);
        $first = new NgrokSettingsSnapshot($version, true, true, null, 'first-secret', 'database');
        $changed = new NgrokSettingsSnapshot($version, false, false, null, 'second-secret', 'environment');

        self::assertSame('sv1:00000000000000000000000000:42', (new NgrokSettingsGeneration)->fingerprint($first));
        self::assertSame((new NgrokSettingsGeneration)->fingerprint($first), (new NgrokSettingsGeneration)->fingerprint($changed));
    }
}
