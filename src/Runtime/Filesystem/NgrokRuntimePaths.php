<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Filesystem;

use RuntimeException;

final readonly class NgrokRuntimePaths
{
    public function root(): string
    {
        $configured = trim((string) config('ngrok-server.runtime_root'));
        if ($configured === '') {
            $configured = storage_path('app/local-gpt/ngrok');
        }
        $path = $this->isAbsolute($configured) ? $configured : base_path($configured);

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    public function ensureRoot(): string
    {
        $root = $this->root();
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create the ngrok runtime root.');
        }
        if (is_link($root)) {
            throw new RuntimeException('The ngrok runtime root cannot be a symbolic link.');
        }

        return $root;
    }

    public function lock(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'ngrok-supervisor.lock';
    }

    public function manifest(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'ngrok-runtime-manifest.json';
    }

    public function ledger(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'ngrok-lifecycle-ledger.json';
    }

    public function spawnGate(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'ngrok-runtime-spawn-gate.json';
    }

    public function snapshot(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'ngrok-runtime-snapshot.json';
    }

    public function shutdownRequest(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'ngrok-shutdown-request.json';
    }

    public function shutdownAcknowledgement(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'ngrok-shutdown-acknowledgement.json';
    }

    public function configDirectory(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'configs';
    }

    public function config(string $spawnAttemptId): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $spawnAttemptId) !== 1) {
            throw new RuntimeException('Invalid ngrok spawn attempt id.');
        }

        return $this->configDirectory().DIRECTORY_SEPARATOR.$spawnAttemptId.'.yml';
    }

    public function relativeToRoot(string $path): string
    {
        $root = $this->root().DIRECTORY_SEPARATOR;
        if (! str_starts_with($path, $root)) {
            throw new RuntimeException('Ngrok runtime artifact is outside the runtime root.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
    }

    public function identity(): string
    {
        $canonical = realpath($this->root()) ?: $this->root();
        if (PHP_OS_FAMILY === 'Windows') {
            $canonical = strtolower($canonical);
        }

        return hash('sha256', str_replace('\\', '/', $canonical));
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
