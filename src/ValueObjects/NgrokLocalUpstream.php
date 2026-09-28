<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\ValueObjects;

use InvalidArgumentException;

final readonly class NgrokLocalUpstream
{
    private function __construct(public string $scheme, public string $host, public int $port) {}

    public static function fromString(string $value): self
    {
        $parts = parse_url(trim($value));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($parts === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The ngrok upstream must be an HTTP or HTTPS loopback origin.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The ngrok upstream cannot contain credentials, query, or fragment.');
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('The ngrok upstream cannot contain a path.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new InvalidArgumentException('The ngrok upstream host must be loopback.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The ngrok upstream port is invalid.');
        }

        return new self($scheme, $host, $port);
    }

    public function value(): string
    {
        $host = $this->host === '::1' ? '[::1]' : $this->host;

        return "{$this->scheme}://{$host}:{$this->port}";
    }

    public function identityUrl(): string
    {
        return $this->value().'/_agenticus-mechanicus/runtime-identity';
    }
}
