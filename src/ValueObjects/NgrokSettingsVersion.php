<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\ValueObjects;

use InvalidArgumentException;

final readonly class NgrokSettingsVersion
{
    public function __construct(
        public string $incarnation,
        public int $revision,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $incarnation) !== 1) {
            throw new InvalidArgumentException('Ngrok settings incarnation must be a canonical uppercase ULID.');
        }
        if ($revision < 0) {
            throw new InvalidArgumentException('Ngrok settings revision cannot be negative.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->incarnation === $other->incarnation && $this->revision === $other->revision;
    }

    /** @return array{incarnation: string, revision: int} */
    public function toArray(): array
    {
        return ['incarnation' => $this->incarnation, 'revision' => $this->revision];
    }
}
