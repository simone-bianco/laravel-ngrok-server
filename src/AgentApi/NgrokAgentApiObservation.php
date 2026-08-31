<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\AgentApi;

final readonly class NgrokAgentApiObservation
{
    public const string READY = 'ready';

    public const string UNAVAILABLE = 'unavailable';

    public const string MISMATCH = 'mismatch';

    /** @param array<string, mixed>|null $endpoint */
    private function __construct(
        public string $state,
        public ?array $endpoint = null,
    ) {}

    /** @param array<string, mixed> $endpoint */
    public static function ready(array $endpoint): self
    {
        return new self(self::READY, $endpoint);
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
