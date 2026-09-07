<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Identity;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeUpstreamProvider;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;

final readonly class ConfiguredNgrokRuntimeUpstreamProvider implements NgrokRuntimeUpstreamProvider
{
    public function upstream(): NgrokLocalUpstream
    {
        return NgrokLocalUpstream::fromString((string) config('ngrok-server.upstream_url'));
    }

    public function hostHeader(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('APP_URL must define the ngrok upstream Host header.');
        }
        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        if (strlen($host) > 253
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) !== 1) {
            throw new RuntimeException('APP_URL contains an invalid ngrok upstream Host header.');
        }

        return $host;
    }
}
