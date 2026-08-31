<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\State;

class NgrokRuntimeStatusSnapshotReader
{
    public function __construct(private readonly NgrokRuntimeSnapshotStore $snapshots) {}

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        return $this->snapshots->read();
    }
}
