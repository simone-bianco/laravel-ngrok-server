<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimoneBianco\LaravelNgrokServer\Process\Environment\NgrokChildEnvironmentBuilder;
use Symfony\Component\Process\Process;

final class NgrokChildEnvironmentBuilderTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    /** @var array<string, mixed> */
    private array $originalServer = [];

    /** @var array<string, mixed> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->sensitiveKeys() as $key) {
            $this->originalEnvironment[$key] = getenv($key);
            $this->originalServer[$key] = $_SERVER[$key] ?? null;
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sensitiveKeys() as $key) {
            $original = $this->originalEnvironment[$key];
            $original === false ? putenv($key) : putenv($key.'='.$original);
            $this->restoreArrayValue($_SERVER, $key, $this->originalServer[$key]);
            $this->restoreArrayValue($_ENV, $key, $this->originalEnv[$key]);
        }
        parent::tearDown();
    }

    public function test_child_process_receives_only_the_application_token_and_approved_os_variables(): void
    {
        putenv('APP_KEY=parent-app-key');
        putenv('DB_PASSWORD=parent-db-password');
        putenv('NGROK_API_KEY=parent-ngrok-api-key');
        putenv('NGROK_CONFIG=parent-ngrok-config');
        putenv('NgRoK_AuThToKeN=pc-global-token');
        $_SERVER['PROVIDER_BEARER_TOKEN'] = 'server-provider-secret';
        $_ENV['CALLBACK_SECRET'] = 'env-callback-secret';

        $environment = (new NgrokChildEnvironmentBuilder)->build('application-token-b');
        $script = tempnam(sys_get_temp_dir(), 'ngrok-env-child-');
        self::assertIsString($script);
        file_put_contents($script, <<<'PHP'
<?php
echo json_encode([
    'app_key' => getenv('APP_KEY'),
    'db_password' => getenv('DB_PASSWORD'),
    'provider' => getenv('PROVIDER_BEARER_TOKEN'),
    'callback' => getenv('CALLBACK_SECRET'),
    'ngrok_api_key' => getenv('NGROK_API_KEY'),
    'ngrok_config' => getenv('NGROK_CONFIG'),
    'ngrok_token' => getenv('NGROK_AUTHTOKEN'),
], JSON_THROW_ON_ERROR);
PHP);

        try {
            $process = new Process([PHP_BINARY, $script], null, $environment);
            $process->setTimeout(10);
            $process->mustRun();
            $visible = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($script);
        }

        self::assertSame(false, $visible['app_key']);
        self::assertSame(false, $visible['db_password']);
        self::assertSame(false, $visible['provider']);
        self::assertSame(false, $visible['callback']);
        self::assertSame(false, $visible['ngrok_api_key']);
        self::assertSame(false, $visible['ngrok_config']);
        self::assertSame('application-token-b', $visible['ngrok_token']);
    }

    /** @return list<string> */
    private function sensitiveKeys(): array
    {
        return ['APP_KEY', 'DB_PASSWORD', 'PROVIDER_BEARER_TOKEN', 'CALLBACK_SECRET', 'NGROK_API_KEY', 'NGROK_CONFIG', 'NgRoK_AuThToKeN', 'NGROK_AUTHTOKEN'];
    }

    /** @param array<string, mixed> $target */
    private function restoreArrayValue(array &$target, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($target[$key]);

            return;
        }
        $target[$key] = $value;
    }
}
