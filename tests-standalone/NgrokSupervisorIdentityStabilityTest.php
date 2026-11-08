<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\TestsStandalone;

use PHPUnit\Framework\TestCase;
use SimoneBianco\LaravelNgrokServer\Process\Launch\OwnedNgrokProcess;
use SimoneBianco\LaravelNgrokServer\Runtime\Identity\RuntimeIdentityObservation;
use SimoneBianco\LaravelNgrokServer\TestsStandalone\Support\HarnessProcessHandle;
use SimoneBianco\LaravelNgrokServer\TestsStandalone\Support\SupervisorHarness;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;

final class NgrokSupervisorIdentityStabilityTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }
        $this->roots = [];
    }

    public function test_verified_identity_publishes_ready(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('ready');
        $harness->queueIdentity([RuntimeIdentityObservation::READY]);

        $harness->reconcile();

        $snapshot = $harness->snapshot();
        self::assertTrue($owned->process->isRunning());
        self::assertSame('ready', $snapshot['summary']);
        self::assertSame('child_active', $harness->ledger()['state']);
        self::assertTrue($snapshot['identity']['verified']);
        self::assertSame('ready', $snapshot['identity']['state']);
        self::assertSame('ready', $snapshot['agent_api']['state']);
        self::assertTrue($snapshot['agent_api']['checked']);
        self::assertSame(4242, $snapshot['child']['pid']);
    }

    public function test_single_identity_timeout_keeps_unverified_owned_child_during_grace(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('ready');
        $harness->queueIdentity([RuntimeIdentityObservation::UNAVAILABLE]);

        $harness->reconcile();

        $snapshot = $harness->snapshot();
        self::assertTrue($owned->process->isRunning());
        self::assertSame('degraded', $snapshot['summary']);
        self::assertContains('upstream_identity_temporarily_unavailable', $snapshot['readiness']['reasons']);
        self::assertFalse($snapshot['identity']['verified']);
        self::assertSame('unavailable', $snapshot['identity']['state']);
        self::assertFalse($snapshot['agent_api']['checked']);
        self::assertSame('unknown', $snapshot['agent_api']['state']);
        self::assertSame('child_active', $harness->ledger()['state']);
    }

    public function test_sustained_identity_timeout_after_verification_never_stops_owned_child(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('ready');
        $harness->queueIdentity([RuntimeIdentityObservation::READY]);
        $harness->reconcile();

        $attempt = $harness->ledger()['spawn_attempt_id'];
        $sequence = $harness->ledger()['state_sequence'];
        $harness->setIdentityUnavailableSince(microtime(true) - 3600);
        $harness->queueIdentity([RuntimeIdentityObservation::UNAVAILABLE]);

        $harness->reconcile();

        $snapshot = $harness->snapshot();
        self::assertTrue($owned->process->isRunning());
        self::assertSame('degraded', $snapshot['summary']);
        self::assertTrue($snapshot['identity']['verified']);
        self::assertSame($attempt, $harness->ledger()['spawn_attempt_id']);
        self::assertSame($sequence, $harness->ledger()['state_sequence']);
        self::assertSame('child_active', $harness->ledger()['state']);
    }

    public function test_identity_mismatch_still_stops_owned_child(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('unavailable');
        $harness->queueIdentity([RuntimeIdentityObservation::MISMATCH]);

        $harness->reconcile();

        self::assertFalse($owned->process->isRunning());
        self::assertSame('clean', $harness->ledger()['state']);
        self::assertFileDoesNotExist($owned->launch->configPath);
        $snapshot = $harness->snapshot();
        self::assertSame('blocked', $snapshot['summary']);
        self::assertContains('upstream_identity_mismatch', $snapshot['readiness']['reasons']);
    }

    public function test_unavailable_identity_before_first_verification_blocks_new_spawn(): void
    {
        $harness = $this->harness();
        // A stale verification flag must never authorize a spawn without a
        // fresh, successful probe for the child that is about to start.
        $harness->setIdentityVerifiedAt(microtime(true) - 10);
        $harness->queueIdentity([RuntimeIdentityObservation::UNAVAILABLE]);

        $harness->reconcile();

        $snapshot = $harness->snapshot();
        self::assertSame('blocked', $snapshot['summary']);
        self::assertContains('upstream_identity_unavailable', $snapshot['readiness']['reasons']);
        self::assertSame('absent', $snapshot['child']['state']);
        self::assertSame('clean', $harness->ledger()['state']);
        self::assertDirectoryDoesNotExist($harness->paths->configDirectory());
    }

    public function test_identity_recovery_returns_ready_without_respawn(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('ready');
        $harness->queueIdentity([RuntimeIdentityObservation::READY]);
        $harness->reconcile();

        $attempt = $harness->ledger()['spawn_attempt_id'];
        $sequence = $harness->ledger()['state_sequence'];
        $harness->setIdentityUnavailableSince(microtime(true) - 3600);
        $harness->queueIdentity([RuntimeIdentityObservation::UNAVAILABLE]);
        $harness->reconcile();

        $harness->clearIdentityProbeRetry();
        $harness->queueIdentity([RuntimeIdentityObservation::READY]);
        $harness->reconcile();

        $snapshot = $harness->snapshot();
        self::assertSame('ready', $snapshot['summary']);
        self::assertSame($attempt, $harness->ledger()['spawn_attempt_id']);
        self::assertSame($sequence, $harness->ledger()['state_sequence']);
        self::assertSame(4242, $snapshot['child']['pid']);
        self::assertTrue($owned->process->isRunning());
    }

    public function test_real_process_exit_reconciles_clean_without_identity_kill(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $owned->process->stop();
        $harness->settings->snapshot = new NgrokSettingsSnapshot(
            $harness->settings->snapshot->version,
            true,
            false,
            $harness->settings->snapshot->externalOrigin,
            $harness->settings->snapshot->authtoken,
            $harness->settings->snapshot->authtokenSource,
        );
        $harness->setAgentApiMode('unavailable');

        $harness->reconcile();

        $snapshot = $harness->snapshot();
        self::assertSame('stopped', $snapshot['summary']);
        self::assertSame('clean', $harness->ledger()['state']);
        self::assertSame('absent', $snapshot['child']['state']);
        self::assertFileDoesNotExist($owned->launch->configPath);
    }

    public function test_agent_api_is_not_marked_unavailable_when_identity_short_circuits(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        // If the Agent API were consulted it would return a healthy endpoint.
        $harness->setAgentApiMode('ready');
        $harness->queueIdentity([RuntimeIdentityObservation::UNAVAILABLE]);

        $harness->reconcile();

        $snapshot = $harness->snapshot();
        self::assertTrue($owned->process->isRunning());
        self::assertSame('unknown', $snapshot['agent_api']['state']);
        self::assertFalse($snapshot['agent_api']['checked']);
        self::assertNull($snapshot['agent_api']['checked_at']);
        self::assertSame('unknown', $snapshot['edge']['state']);
        self::assertSame('temporarily_unavailable', $snapshot['upstream']['state']);
    }

    public function test_delayed_identity_result_is_ignored_when_owned_attempt_changes(): void
    {
        $harness = $this->harness();
        $first = $harness->attachOwned();
        $replacement = new OwnedNgrokProcess(new HarnessProcessHandle, $first->launch);
        $harness->setIdentityCallback(static function () use ($harness, $replacement): void {
            $harness->replaceOwned($replacement);
        });
        $harness->queueIdentity([RuntimeIdentityObservation::MISMATCH]);

        $harness->reconcile();

        self::assertTrue($first->process->isRunning());
        self::assertTrue($replacement->process->isRunning());
        self::assertNotSame('blocked', $harness->snapshot()['summary']);
        self::assertNotContains('upstream_identity_mismatch', $harness->snapshot()['readiness']['reasons'] ?? []);
    }

    public function test_agent_api_unavailability_still_uses_grace_and_stops_owned_child(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned(microtime(true) - 100);
        $harness->setAgentApiMode('unavailable');
        $harness->setAgentApiUnavailableSince(microtime(true) - 3600);
        $harness->queueIdentity([RuntimeIdentityObservation::READY]);

        $harness->reconcile();

        self::assertFalse($owned->process->isRunning());
        self::assertSame('clean', $harness->ledger()['state']);
        $snapshot = $harness->snapshot();
        self::assertSame('blocked', $snapshot['summary']);
        self::assertContains('agent_api_unavailable', $snapshot['readiness']['reasons']);
    }

    public function test_agent_api_endpoint_mismatch_still_stops_owned_child(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('mismatch');
        $harness->queueIdentity([RuntimeIdentityObservation::READY]);

        $harness->reconcile();

        self::assertFalse($owned->process->isRunning());
        self::assertSame('clean', $harness->ledger()['state']);
        $snapshot = $harness->snapshot();
        self::assertSame('blocked', $snapshot['summary']);
        self::assertContains('agent_api_endpoint_mismatch', $snapshot['readiness']['reasons']);
    }

    public function test_hard_configuration_change_still_stops_owned_child(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('unavailable');
        $harness->settings->snapshot = new NgrokSettingsSnapshot(
            $harness->settings->snapshot->version,
            true,
            true,
            $harness->settings->snapshot->externalOrigin,
            'changed-token',
            'test',
        );

        $harness->reconcile();

        self::assertFalse($owned->process->isRunning());
        self::assertSame('clean', $harness->ledger()['state']);
        $snapshot = $harness->snapshot();
        self::assertSame('blocked', $snapshot['summary']);
        self::assertContains('hard_configuration_blocker', $snapshot['readiness']['reasons']);
    }

    public function test_ledger_and_recovery_state_unchanged_on_transient_identity_unavailability(): void
    {
        $harness = $this->harness();
        $owned = $harness->attachOwned();
        $harness->setAgentApiMode('ready');
        $harness->queueIdentity([RuntimeIdentityObservation::READY]);
        $harness->reconcile();
        $before = $harness->ledger();

        $harness->setIdentityUnavailableSince(microtime(true) - 3600);
        $harness->queueIdentity([RuntimeIdentityObservation::UNAVAILABLE]);
        $harness->reconcile();
        $after = $harness->ledger();

        self::assertSame('child_active', $after['state']);
        self::assertSame($before['state_sequence'], $after['state_sequence']);
        self::assertSame($before['spawn_attempt_id'], $after['spawn_attempt_id']);
        self::assertTrue($owned->process->isRunning());
        self::assertNotSame('recovery_required', $harness->snapshot()['summary']);
    }

    private function harness(): SupervisorHarness
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-standalone-'.bin2hex(random_bytes(8));
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            self::fail('Unable to create the standalone runtime root.');
        }
        $this->roots[] = $root;

        return new SupervisorHarness($root);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $entries = @scandir($directory);
        if (! is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
