<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\ValueObjects;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class NgrokHostHeaderPolicy
{
    /** @return array<string, mixed> */
    public static function definition(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && (strlen($host) > 253 || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) !== 1)) {
            throw new InvalidArgumentException('The ngrok upstream Host must be a valid hostname or IP address.');
        }

        return ['on_http_request' => [['actions' => [[
            'type' => 'add-headers',
            'config' => ['headers' => ['host' => $host]],
        ]]]]];
    }

    public static function matches(mixed $observed, string $host): bool
    {
        if (! is_string($observed) || $observed === '') {
            return false;
        }
        try {
            $policy = Yaml::parse($observed);
        } catch (\Throwable) {
            return false;
        }

        return is_array($policy) && self::ordered($policy) === self::ordered(self::definition($host));
    }

    /** @param array<mixed> $value @return array<mixed> */
    private static function ordered(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::ordered($item);
            }
        }

        return $value;
    }
}
