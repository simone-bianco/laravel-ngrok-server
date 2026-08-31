<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Filesystem;

use JsonException;
use RuntimeException;

final class DurableRuntimeFile
{
    public function __construct(private readonly WindowsAtomicFileReplacer $windowsFiles) {}

    /** @return array<string, mixed>|null */
    public function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if (! is_string($content)) {
            throw new RuntimeException("Unable to read runtime file [$path].");
        }
        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Runtime file [$path] contains invalid JSON.", previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("Runtime file [$path] must contain a JSON object.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $value */
    public function createJsonExclusive(string $path, array $value): array
    {
        $content = $this->encode($value);
        $this->writeExclusive($path, $content, retainOnFailure: true);

        return $this->verifyJson($path, $value);
    }

    /** @param array<string, mixed> $value */
    public function replaceJson(string $path, array $value): array
    {
        $content = $this->encode($value);
        $this->replace($path, $content);

        return $this->verifyJson($path, $value);
    }

    public function createTextExclusive(string $path, string $content): void
    {
        $this->writeExclusive($path, $content, retainOnFailure: false);
        $read = @file_get_contents($path);
        if (! is_string($read) || ! hash_equals(hash('sha256', $content), hash('sha256', $read))) {
            throw new RuntimeException("Runtime file [$path] failed read-back validation.");
        }
    }

    private function writeExclusive(string $path, string $content, bool $retainOnFailure): void
    {
        $this->ensureDirectory(dirname($path));
        $handle = @fopen($path, 'x+b');
        if (! is_resource($handle)) {
            throw new RuntimeException("Runtime file [$path] already exists or cannot be created exclusively.");
        }
        try {
            $this->writeAndSync($handle, $content, $path);
        } catch (\Throwable $exception) {
            fclose($handle);
            if (! $retainOnFailure) {
                @unlink($path);
            }
            // Manifest/ledger paths are monotonic safety evidence. Once an
            // exclusive create succeeded, even an incomplete file must remain
            // visible so the next process enters recovery instead of treating
            // the installation as fresh.
            throw $exception;
        }
        fclose($handle);
    }

    private function replace(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (! is_resource($handle)) {
            throw new RuntimeException("Unable to create temporary runtime file for [$path].");
        }
        try {
            $this->writeAndSync($handle, $content, $temporary);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $this->windowsFiles->replace($temporary, $path);
            } elseif (! @rename($temporary, $path)) {
                throw new RuntimeException("Unable to atomically replace runtime file [$path].");
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    /** @param resource $handle */
    private function writeAndSync($handle, string $content, string $path): void
    {
        $length = strlen($content);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($handle, substr($content, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException("Unable to write runtime file [$path].");
            }
            $written += $count;
        }
        if (! fflush($handle)) {
            throw new RuntimeException("Unable to flush runtime file [$path].");
        }
        if (function_exists('fsync') && ! fsync($handle)) {
            throw new RuntimeException("Unable to durably sync runtime file [$path].");
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create runtime directory [$directory].");
        }
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    private function verifyJson(string $path, array $expected): array
    {
        $actual = $this->readJson($path);
        if ($actual === null || ! hash_equals(hash('sha256', $this->encode($expected)), hash('sha256', $this->encode($actual)))) {
            throw new RuntimeException("Runtime file [$path] failed read-back validation.");
        }

        return $actual;
    }
}
