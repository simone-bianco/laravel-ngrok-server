<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\ValueObjects;

use InvalidArgumentException;

final readonly class RemoteExternalOrigin
{
    private function __construct(public string $host) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);
        $parts = parse_url($value);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException('Remote external origin must use HTTPS.');
        }
        if (isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Remote external origin cannot contain credentials.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Remote external origin cannot contain a query or fragment.');
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('Remote external origin cannot contain a path.');
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== 443) {
            throw new InvalidArgumentException('Remote external origin only supports the default HTTPS port.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        self::assertDnsHost($host);

        return new self($host);
    }

    public static function tryFrom(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return self::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function value(): string
    {
        return 'https://'.$this->host;
    }

    public function matchesAuthority(?string $authority): bool
    {
        if ($authority === null) {
            return false;
        }
        $authority = trim($authority);
        if ($authority === '' || preg_match('/\s/', $authority) === 1 || str_contains($authority, ',') || str_contains($authority, '/') || str_contains($authority, '\\')) {
            return false;
        }
        $host = $authority;
        $port = null;
        if (substr_count($authority, ':') === 1) {
            [$host, $rawPort] = explode(':', $authority, 2);
            if ($rawPort === '' || ! ctype_digit($rawPort)) {
                return false;
            }
            $port = (int) $rawPort;
        } elseif (str_contains($authority, ':')) {
            return false;
        }

        return strtolower($host) === $this->host && ($port === null || $port === 443);
    }

    private static function assertDnsHost(string $host): void
    {
        if ($host === '' || strlen($host) > 253 || str_ends_with($host, '.')) {
            throw new InvalidArgumentException('Remote external origin host is invalid.');
        }
        if (preg_match('/[^\x21-\x7e]/', $host) === 1 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Remote external origin must use an ASCII DNS hostname.');
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            throw new InvalidArgumentException('Remote external origin must use a qualified DNS hostname.');
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) {
                throw new InvalidArgumentException('Remote external origin contains an invalid DNS label.');
            }
        }
    }
}
