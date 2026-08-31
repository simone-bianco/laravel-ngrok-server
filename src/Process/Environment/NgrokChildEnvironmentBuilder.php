<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Environment;

final class NgrokChildEnvironmentBuilder
{
    private const array WINDOWS_ALLOWLIST = ['SystemRoot', 'WINDIR', 'TEMP', 'TMP'];

    private const array UNIX_ALLOWLIST = ['HOME', 'TMPDIR', 'TMP', 'TEMP'];

    /** @return array<string, string|false> */
    public function build(?string $authtoken = null): array
    {
        $sources = [];
        $environment = getenv();
        if (is_array($environment)) {
            $sources[] = $environment;
        }
        $sources[] = $_SERVER;
        $sources[] = $_ENV;
        $values = [];
        $deny = [];
        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                $deny[$key] = false;
                if ((is_string($value) || is_numeric($value)) && ! array_key_exists(strtolower($key), $values)) {
                    $values[strtolower($key)] = (string) $value;
                }
            }
        }
        foreach (PHP_OS_FAMILY === 'Windows' ? self::WINDOWS_ALLOWLIST : self::UNIX_ALLOWLIST as $allowed) {
            $value = $values[strtolower($allowed)] ?? null;
            if (is_string($value) && $value !== '') {
                $deny[$allowed] = $value;
            }
        }
        if ($authtoken !== null && trim($authtoken) !== '') {
            $deny['NGROK_AUTHTOKEN'] = trim($authtoken);
        }

        return $deny;
    }
}
