<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Binary;

final readonly class NgrokBinary
{
    public function __construct(public string $path, public string $version) {}
}
