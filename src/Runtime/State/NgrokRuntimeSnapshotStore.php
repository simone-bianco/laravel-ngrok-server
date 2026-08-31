<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\State;

use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;

final readonly class NgrokRuntimeSnapshotStore
{
    public function __construct(private NgrokRuntimePaths $paths, private DurableRuntimeFile $files) {}

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        return $this->files->readJson($this->paths->snapshot());
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function publish(array $snapshot): array
    {
        $snapshot = array_merge([
            'schema_version' => (int) config('ngrok-server.snapshot_schema_version', 1),
            'observed_at' => gmdate(DATE_ATOM),
        ], $snapshot);

        return $this->files->replaceJson($this->paths->snapshot(), $snapshot);
    }
}
