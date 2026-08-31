<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;

final readonly class NgrokSupervisorShutdownSignal
{
    public function __construct(
        private NgrokRuntimePaths $paths,
        private DurableRuntimeFile $files,
    ) {}

    public function request(string $reason = 'launcher_shutdown', ?NgrokSettingsVersion $targetVersion = null): string
    {
        $this->paths->ensureRoot();
        $requestId = bin2hex(random_bytes(16));
        $this->files->replaceJson($this->paths->shutdownRequest(), [
            'schema_version' => 1,
            'request_id' => $requestId,
            'reason' => mb_substr(trim($reason) !== '' ? trim($reason) : 'shutdown_requested', 0, 80),
            'requested_at' => gmdate(DATE_ATOM),
            'target_version' => $targetVersion?->toArray(),
        ]);

        return $requestId;
    }

    public function requested(): bool
    {
        return $this->pendingFor(null, includeTargeted: true) !== null;
    }

    /** @return array{request_id: string, reason: string, target_version: array{incarnation: string, revision: int}|null}|null */
    public function pendingFor(?NgrokSettingsVersion $current, bool $includeTargeted = false): ?array
    {
        $request = $this->files->readJson($this->paths->shutdownRequest());
        if ($request === null) {
            return null;
        }
        $requestId = $request['request_id'] ?? null;
        $reason = $request['reason'] ?? null;
        $target = $request['target_version'] ?? null;
        if (($request['schema_version'] ?? null) !== 1
            || ! is_string($requestId)
            || preg_match('/\A[a-f0-9]{32}\z/D', $requestId) !== 1
            || ! is_string($reason)) {
            throw new RuntimeException('The ngrok supervisor shutdown request is invalid.');
        }

        $acknowledgement = $this->files->readJson($this->paths->shutdownAcknowledgement());
        if (($acknowledgement['request_id'] ?? null) === $requestId) {
            return null;
        }
        if ($target === null) {
            return ['request_id' => $requestId, 'reason' => $reason, 'target_version' => null];
        }
        if (! is_array($target)
            || ! is_string($target['incarnation'] ?? null)
            || ! is_int($target['revision'] ?? null)) {
            throw new RuntimeException('The ngrok supervisor shutdown target is invalid.');
        }
        if (! $includeTargeted && ($current === null
            || ! $current->equals(new NgrokSettingsVersion($target['incarnation'], $target['revision'])))) {
            return null;
        }

        return ['request_id' => $requestId, 'reason' => $reason, 'target_version' => $target];
    }

    public function acknowledge(string $requestId): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $requestId) !== 1) {
            throw new RuntimeException('Invalid ngrok supervisor shutdown request id.');
        }
        $this->files->replaceJson($this->paths->shutdownAcknowledgement(), [
            'schema_version' => 1,
            'request_id' => $requestId,
            'acknowledged_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function reset(): void
    {
        @unlink($this->paths->shutdownRequest());
        @unlink($this->paths->shutdownAcknowledgement());
    }
}
