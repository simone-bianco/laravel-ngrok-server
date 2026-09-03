<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Identity;

final readonly class RuntimeIdentityObservation
{
    public const string READY = 'ready';

    public const string UNAVAILABLE = 'unavailable';

    public const string MISMATCH = 'mismatch';

    private function __construct(public string $state) {}

    public static function ready(): self
    {
        return new self(self::READY);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public static function mismatch(): self
    {
        return new self(self::MISMATCH);
    }
}
