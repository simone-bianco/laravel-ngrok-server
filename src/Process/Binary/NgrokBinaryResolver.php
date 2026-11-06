<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Binary;

use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Environment\NgrokChildEnvironmentBuilder;
use Symfony\Component\Process\Process;

final readonly class NgrokBinaryResolver
{
    public function __construct(private NgrokChildEnvironmentBuilder $environment) {}

    public function resolve(): NgrokBinary
    {
        $configured = trim((string) config('ngrok-server.binary', 'ngrok'));
        $path = $this->resolvePath($configured);
        $process = new Process([$path, 'version'], null, $this->environment->build());
        $process->setTimeout(5);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('The configured ngrok binary failed its version preflight.');
        }
        $version = trim($process->getOutput());
        if ($version === '' || ! str_contains(strtolower($version), 'ngrok version 3.')) {
            throw new RuntimeException('Local GPT requires an ngrok v3 agent.');
        }
        $help = new Process([$path, 'start', '--help'], null, $this->environment->build());
        $help->setTimeout(5);
        $help->run();
        $output = $help->getOutput().$help->getErrorOutput();
        foreach (['--config'] as $requiredFlag) {
            if (! $help->isSuccessful() || ! str_contains($output, $requiredFlag)) {
                throw new RuntimeException("The configured ngrok agent does not support required flag [$requiredFlag].");
            }
        }

        return new NgrokBinary($path, $version);
    }

    public function validateConfig(NgrokBinary $binary, string $configPath): void
    {
        $process = new Process([$binary->path, 'config', 'check', '--config', $configPath], null, $this->environment->build());
        $process->setTimeout(5);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('The generated ngrok agent configuration failed validation.');
        }
    }

    private function resolvePath(string $configured): string
    {
        if ($configured === '') {
            throw new RuntimeException('The ngrok binary is not configured.');
        }
        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return realpath($configured) ?: $configured;
        }
        $lookup = PHP_OS_FAMILY === 'Windows'
            ? new Process(['where.exe', $configured])
            : new Process(['which', $configured]);
        $lookup->setTimeout(5);
        $lookup->run();
        if (! $lookup->isSuccessful()) {
            throw new RuntimeException('Unable to locate the ngrok binary.');
        }
        $candidate = trim(strtok($lookup->getOutput(), "\r\n") ?: '');
        if ($candidate === '') {
            throw new RuntimeException('The resolved ngrok binary path is invalid.');
        }

        // Windows App Execution Aliases can be executable while PHP reports
        // is_file() === false. The version preflight is the executable proof.
        return realpath($candidate) ?: $candidate;
    }
}
