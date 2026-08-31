<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\State;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

final readonly class NgrokLifecycleLedgerStore
{
    private const array STATES = ['clean', 'spawn_armed', 'child_active', 'stopping', 'spawn_uncertain'];

    public function __construct(private NgrokRuntimePaths $paths, private DurableRuntimeFile $files) {}

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        return $this->files->readJson($this->paths->ledger());
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function createCleanExclusive(array $manifest): array
    {
        $ledger = $this->files->createJsonExclusive($this->paths->ledger(), [
            'schema_version' => (int) config('ngrok-server.ledger_schema_version', 1),
            'installation_runtime_id' => $manifest['installation_runtime_id'],
            'bootstrap_attempt_id' => $manifest['bootstrap_attempt_id'],
            'state' => 'clean',
            'state_sequence' => 0,
            'spawn_attempt_id' => null,
            'endpoint_instance_id' => null,
            'supervisor_instance_id' => null,
            'settings_version' => null,
            'boot_epoch_id' => null,
            'agent_api_address' => null,
            'canonical_origin' => null,
            'canonical_upstream' => null,
            'runtime_config_path' => null,
            'runtime_config_sha256' => null,
            'process_ownership_type' => null,
            'process_ownership_id' => null,
            'pid' => null,
            'written_at' => gmdate(DATE_ATOM),
            'last_verified_clean_at' => gmdate(DATE_ATOM),
        ]);
        $this->assertLinked($manifest, $ledger);

        return $ledger;
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $ledger */
    public function assertLinked(array $manifest, array $ledger): void
    {
        $state = $ledger['state'] ?? null;
        $sequence = $ledger['state_sequence'] ?? null;
        if (($ledger['schema_version'] ?? null) !== (int) config('ngrok-server.ledger_schema_version', 1)
            || ! $this->isHexId($ledger['installation_runtime_id'] ?? null)
            || ($ledger['installation_runtime_id'] ?? null) !== ($manifest['installation_runtime_id'] ?? null)
            || ! $this->isHexId($ledger['bootstrap_attempt_id'] ?? null)
            || ($ledger['bootstrap_attempt_id'] ?? null) !== ($manifest['bootstrap_attempt_id'] ?? null)
            || ! in_array($state, self::STATES, true)
            || ! is_int($sequence)
            || $sequence < 0
            || ! $this->isTimestamp($ledger['written_at'] ?? null)
            || ! $this->isTimestamp($ledger['last_verified_clean_at'] ?? null)
            || ! $this->stateFieldsAreValid($ledger, (string) $state)) {
            throw new RuntimeException('Ngrok lifecycle ledger is invalid or not linked to the runtime manifest.');
        }
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $fields @return array<string, mixed> */
    public function arm(array $manifest, array $fields): array
    {
        $ledger = $this->requireState($manifest, 'clean');

        return $this->transition($manifest, $ledger, 'spawn_armed', array_merge($fields, [
            'pid' => null,
            'last_verified_clean_at' => $ledger['last_verified_clean_at'] ?? null,
        ]));
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function markActive(array $manifest, string $spawnAttemptId, int $pid): array
    {
        $ledger = $this->requireAttempt($manifest, $spawnAttemptId, ['spawn_armed', 'spawn_uncertain']);

        return $this->transition($manifest, $ledger, 'child_active', ['pid' => $pid]);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function markStopping(array $manifest, string $spawnAttemptId): array
    {
        $ledger = $this->requireAttempt($manifest, $spawnAttemptId, ['spawn_armed', 'child_active', 'spawn_uncertain']);

        return $this->transition($manifest, $ledger, 'stopping', []);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function markUncertain(array $manifest, string $spawnAttemptId): array
    {
        $ledger = $this->requireAttempt($manifest, $spawnAttemptId, ['spawn_armed', 'child_active']);

        return $this->transition($manifest, $ledger, 'spawn_uncertain', []);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function markClean(array $manifest, string $spawnAttemptId): array
    {
        $ledger = $this->requireAttempt($manifest, $spawnAttemptId, ['spawn_armed', 'child_active', 'stopping', 'spawn_uncertain']);

        return $this->transition($manifest, $ledger, 'clean', [
            'spawn_attempt_id' => null,
            'endpoint_instance_id' => null,
            'supervisor_instance_id' => null,
            'settings_version' => null,
            'boot_epoch_id' => null,
            'agent_api_address' => null,
            'canonical_origin' => null,
            'canonical_upstream' => null,
            'runtime_config_path' => null,
            'runtime_config_sha256' => null,
            'process_ownership_type' => null,
            'process_ownership_id' => null,
            'pid' => null,
            'last_verified_clean_at' => gmdate(DATE_ATOM),
        ]);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function requireState(array $manifest, string $state): array
    {
        $ledger = $this->read() ?? throw new RuntimeException('Ngrok lifecycle ledger is missing.');
        $this->assertLinked($manifest, $ledger);
        if (($ledger['state'] ?? null) !== $state) {
            throw new RuntimeException("Ngrok lifecycle ledger is not [$state].");
        }

        return $ledger;
    }

    /** @param array<string, mixed> $manifest @param list<string> $states @return array<string, mixed> */
    private function requireAttempt(array $manifest, string $attemptId, array $states): array
    {
        $ledger = $this->read() ?? throw new RuntimeException('Ngrok lifecycle ledger is missing.');
        $this->assertLinked($manifest, $ledger);
        if (($ledger['spawn_attempt_id'] ?? null) !== $attemptId || ! in_array($ledger['state'] ?? null, $states, true)) {
            throw new RuntimeException('Ngrok lifecycle ledger attempt does not match the owned child.');
        }

        return $ledger;
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $ledger @param array<string, mixed> $changes @return array<string, mixed> */
    private function transition(array $manifest, array $ledger, string $state, array $changes): array
    {
        $this->assertLinked($manifest, $ledger);
        $next = array_merge($ledger, $changes, [
            'state' => $state,
            'state_sequence' => ((int) $ledger['state_sequence']) + 1,
            'written_at' => gmdate(DATE_ATOM),
        ]);
        $this->assertLinked($manifest, $next);

        return $this->files->replaceJson($this->paths->ledger(), $next);
    }

    /** @param array<string, mixed> $ledger */
    private function stateFieldsAreValid(array $ledger, string $state): bool
    {
        if ($state === 'clean') {
            foreach ([
                'spawn_attempt_id', 'endpoint_instance_id', 'supervisor_instance_id',
                'settings_version', 'boot_epoch_id', 'agent_api_address',
                'canonical_origin', 'canonical_upstream', 'runtime_config_path',
                'runtime_config_sha256', 'process_ownership_type',
                'process_ownership_id', 'pid',
            ] as $field) {
                if (($ledger[$field] ?? null) !== null) {
                    return false;
                }
            }

            return true;
        }

        $attempt = $ledger['spawn_attempt_id'] ?? null;
        $settingsVersion = $ledger['settings_version'] ?? null;
        $configPath = $ledger['runtime_config_path'] ?? null;
        $ownershipType = $ledger['process_ownership_type'] ?? null;
        $ownershipId = $ledger['process_ownership_id'] ?? null;
        $pid = $ledger['pid'] ?? null;
        if (! $this->isHexId($attempt)
            || ! $this->isHexId($ledger['endpoint_instance_id'] ?? null)
            || ! $this->isHexId($ledger['supervisor_instance_id'] ?? null)
            || ! is_array($settingsVersion)
            || ! is_string($settingsVersion['incarnation'] ?? null)
            || trim((string) $settingsVersion['incarnation']) === ''
            || ! is_int($settingsVersion['revision'] ?? null)
            || (int) $settingsVersion['revision'] < 0
            || ! $this->isHexId($ledger['boot_epoch_id'] ?? null)
            || ! $this->isLoopbackAgentAddress($ledger['agent_api_address'] ?? null)
            || ! $this->isCanonicalOrigin($ledger['canonical_origin'] ?? null)
            || ! $this->isCanonicalUpstream($ledger['canonical_upstream'] ?? null)
            || ! is_string($configPath)
            || $configPath !== 'configs/'.$attempt.'.yml'
            || ! is_string($ledger['runtime_config_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $ledger['runtime_config_sha256']) !== 1
            || ! in_array($ownershipType, [NgrokProcessOwnership::WINDOWS_JOB, NgrokProcessOwnership::POSIX_PROCESS], true)
            || ($ownershipType === NgrokProcessOwnership::WINDOWS_JOB && (! is_string($ownershipId) || $ownershipId === ''))
            || ($ownershipType === NgrokProcessOwnership::POSIX_PROCESS && $ownershipId !== null)) {
            return false;
        }
        if ($state === 'spawn_armed') {
            return $pid === null;
        }
        if ($state === 'child_active') {
            return is_int($pid) && $pid > 0;
        }

        return $pid === null || (is_int($pid) && $pid > 0);
    }

    private function isHexId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1;
    }

    private function isTimestamp(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }

    private function isLoopbackAgentAddress(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && ($parts['host'] ?? null) === '127.0.0.1'
            && is_int($parts['port'] ?? null)
            && $parts['port'] >= 1
            && $parts['port'] <= 65535
            && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && in_array((string) ($parts['path'] ?? ''), ['', '/'], true);
    }

    private function isCanonicalOrigin(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $origin = RemoteExternalOrigin::tryFrom($value);

        return $origin !== null && $origin->value() === $value;
    }

    private function isCanonicalUpstream(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        try {
            return NgrokLocalUpstream::fromString($value)->value() === $value;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
