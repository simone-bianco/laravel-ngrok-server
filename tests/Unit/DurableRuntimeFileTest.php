<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\WindowsAtomicFileReplacer;

final class DurableRuntimeFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-runtime-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_exclusive_create_and_atomic_replace_round_trip_complete_json_objects(): void
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'state.json';
        $files = new DurableRuntimeFile(new WindowsAtomicFileReplacer);

        self::assertSame(['sequence' => 1], $files->createJsonExclusive($path, ['sequence' => 1]));
        self::assertSame(['sequence' => 2], $files->replaceJson($path, ['sequence' => 2]));
        self::assertSame(['sequence' => 2], $files->readJson($path));
        self::assertSame([], glob($this->root.DIRECTORY_SEPARATOR.'.*.tmp') ?: []);
    }

    public function test_exclusive_create_refuses_to_overwrite_runtime_evidence(): void
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'manifest.json';
        $files = new DurableRuntimeFile(new WindowsAtomicFileReplacer);
        $files->createJsonExclusive($path, ['state' => 'initializing']);

        $this->expectException(RuntimeException::class);
        $files->createJsonExclusive($path, ['state' => 'initialized']);
    }
}
