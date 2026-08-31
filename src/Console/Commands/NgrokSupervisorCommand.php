<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeSettingsSnapshotProvider;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisorShutdownSignal;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final class NgrokSupervisorCommand extends Command
{
    protected $signature;

    protected $description = 'Reconcile the installation-scoped ngrok desired state and own its child process';

    private bool $stopping = false;

    private ?\Closure $windowsControlHandler = null;

    public function __construct()
    {
        $this->signature = (string) config('ngrok-server.commands.supervisor', 'ngrok-server:ngrok-supervisor').'
            {--non-fatal-unavailable : Return success when another owner, bootstrap or recovery prevents startup}
            {--wait-for-runtime : Keep the dev process alive and retry blocked startup states safely}
            {--desired-incarnation= : Bound desired-state incarnation for detached starts}
            {--desired-revision= : Bound desired-state revision for detached starts}';
        parent::__construct();
    }

    public function handle(
        NgrokSupervisor $supervisor,
        NgrokSupervisorShutdownSignal $shutdownSignal,
        NgrokRuntimeSettingsSnapshotProvider $settings,
    ): int {
        $this->installSignalTraps($shutdownSignal);
        $generation = $this->requestedGeneration();

        try {
            $lastReportedExit = null;

            do {
                if ($generation !== null && ! $this->generationRemainsActive($generation, $settings)) {
                    return self::SUCCESS;
                }
                $exit = $supervisor->run(fn (): bool => $this->stopping, $generation);
                if ($exit === self::SUCCESS || $this->stopping) {
                    if ($exit === NgrokSupervisor::EXIT_RECOVERY_REQUIRED) {
                        return $exit;
                    }

                    return self::SUCCESS;
                }

                if ($lastReportedExit !== $exit) {
                    $this->reportBlockedExit($exit);
                    $lastReportedExit = $exit;
                }

                if (! $this->option('wait-for-runtime') || ! $this->isBlockedExit($exit)) {
                    break;
                }

                $this->waitInterruptibly(
                    $exit === NgrokSupervisor::EXIT_RECOVERY_REQUIRED ? 10 : 2,
                    $shutdownSignal,
                    $generation,
                    $settings,
                );
            } while (! $this->stopping);

            if ($this->option('non-fatal-unavailable') && $this->isBlockedExit($exit)) {
                return self::SUCCESS;
            }

            return $exit;
        } finally {
            $this->removeWindowsControlHandler();
        }
    }

    private function installSignalTraps(NgrokSupervisorShutdownSignal $shutdownSignal): void
    {
        $requestShutdown = function () use ($shutdownSignal): void {
            $this->stopping = true;
            try {
                $shutdownSignal->request('console_signal');
            } catch (\Throwable) {
                // The in-process stop flag still gives the supervisor a cleanup path.
            }
        };

        if (! method_exists($this, 'trap')) {
            $signals = [];
        } else {
            $signals = array_values(array_filter([
                defined('SIGINT') ? SIGINT : null,
                defined('SIGTERM') ? SIGTERM : null,
            ], static fn ($signal): bool => is_int($signal)));
        }
        if ($signals !== []) {
            $this->trap($signals, $requestShutdown);
        }

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            $this->windowsControlHandler = function () use ($requestShutdown): bool {
                $requestShutdown();

                return true;
            };
            sapi_windows_set_ctrl_handler($this->windowsControlHandler);
        }
    }

    private function removeWindowsControlHandler(): void
    {
        if ($this->windowsControlHandler === null || ! function_exists('sapi_windows_set_ctrl_handler')) {
            return;
        }

        sapi_windows_set_ctrl_handler($this->windowsControlHandler, false);
        $this->windowsControlHandler = null;
    }

    private function reportBlockedExit(int $exit): void
    {
        if ($exit === NgrokSupervisor::EXIT_LOCK_HELD) {
            $this->warn('Another ngrok supervisor owns this installation (LOCK_HELD).');
        } elseif ($exit === NgrokSupervisor::EXIT_BOOTSTRAP_REQUIRED) {
            $this->warn('Ngrok runtime bootstrap is required. Run '.config('ngrok-server.commands.bootstrap', 'ngrok-server:ngrok-bootstrap').' explicitly.');
        } elseif ($exit === NgrokSupervisor::EXIT_RECOVERY_REQUIRED) {
            $this->error('Ngrok runtime recovery is required; no process was spawned.');
        }
    }

    private function isBlockedExit(int $exit): bool
    {
        return in_array($exit, [
            NgrokSupervisor::EXIT_LOCK_HELD,
            NgrokSupervisor::EXIT_BOOTSTRAP_REQUIRED,
            NgrokSupervisor::EXIT_RECOVERY_REQUIRED,
        ], true);
    }

    private function waitInterruptibly(
        int $seconds,
        NgrokSupervisorShutdownSignal $shutdownSignal,
        ?NgrokSettingsVersion $generation,
        NgrokRuntimeSettingsSnapshotProvider $settings,
    ): void {
        $ticks = max(1, $seconds * 10);
        for ($tick = 0; $tick < $ticks && ! $this->stopping && ! $shutdownSignal->requested(); $tick++) {
            if ($generation !== null && ! $this->generationRemainsActive($generation, $settings)) {
                return;
            }
            usleep(100_000);
        }
    }

    private function requestedGeneration(): ?NgrokSettingsVersion
    {
        $incarnation = $this->option('desired-incarnation');
        $revision = $this->option('desired-revision');
        if ($incarnation === null && $revision === null) {
            return null;
        }
        if (! is_string($incarnation)
            || trim($incarnation) === ''
            || ! is_string($revision)
            || preg_match('/\A\d+\z/D', $revision) !== 1) {
            throw new RuntimeException('Detached ngrok supervisor generation is invalid.');
        }

        return new NgrokSettingsVersion($incarnation, (int) $revision);
    }

    private function generationRemainsActive(
        NgrokSettingsVersion $generation,
        NgrokRuntimeSettingsSnapshotProvider $settings,
    ): bool {
        try {
            $current = $settings->capture(1);

            return $current->desiredEnabled && $current->version->equals($generation);
        } catch (\Throwable) {
            return false;
        }
    }
}
