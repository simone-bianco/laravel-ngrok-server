<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use ReflectionMethod;
use SimoneBianco\LaravelNgrokServer\Console\Commands\NgrokSupervisorCommand;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class NgrokSupervisorCommandGenerationTest extends TestCase
{
    public function test_wait_mode_keeps_retrying_lock_held_only_for_its_active_generation(): void
    {
        $command = new NgrokSupervisorCommand;
        $generation = $this->ngrokSettings->currentVersion();
        $this->ngrokSettings->snapshot = $this->snapshot($generation, true);

        self::assertTrue($this->invoke($command, 'isBlockedExit', [NgrokSupervisor::EXIT_LOCK_HELD]));
        self::assertTrue($this->invoke($command, 'generationRemainsActive', [$generation, $this->ngrokSettings]));

        $this->ngrokSettings->snapshot = $this->snapshot($generation, false);
        self::assertFalse($this->invoke($command, 'generationRemainsActive', [$generation, $this->ngrokSettings]));

        $this->ngrokSettings->snapshot = $this->snapshot(new NgrokSettingsVersion($generation->incarnation, $generation->revision + 1), true);
        self::assertFalse($this->invoke($command, 'generationRemainsActive', [$generation, $this->ngrokSettings]));
    }

    private function invoke(object $target, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }

    private function snapshot(NgrokSettingsVersion $version, bool $desiredEnabled): NgrokSettingsSnapshot
    {
        $current = $this->ngrokSettings->snapshot;

        return new NgrokSettingsSnapshot(
            $version,
            $current->remoteAccessEnabled,
            $desiredEnabled,
            $current->externalOrigin,
            $current->authtoken,
            $current->authtokenSource,
            $current->directWebEnabled,
            $current->directApiEnabled,
            $current->ngrokWebEnabled,
            $current->ngrokApiEnabled,
        );
    }
}
