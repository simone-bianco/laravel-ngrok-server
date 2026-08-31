<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Ownership;

final readonly class NgrokProcessOwnership
{
    public const string WINDOWS_JOB = 'windows_job';

    public const string POSIX_PROCESS = 'posix_process';

    public function __construct(
        public string $type,
        public ?string $id,
    ) {}

    public function isHandleBound(): bool
    {
        return $this->type === self::WINDOWS_JOB;
    }
}
