<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\NgrokSupervisor;
use SimoneBianco\LaravelNgrokServer\Runtime\Bootstrap\NgrokRuntimeBootstrapper;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokLifecycleLedgerStore;
use SimoneBianco\LaravelNgrokServer\Runtime\State\NgrokRuntimeSnapshotStore;
use SimoneBianco\LaravelNgrokServer\Tests\TestCase;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

final class NgrokSupervisorPreflightTest extends TestCase
{
    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-preflight-'.bin2hex(random_bytes(8));
        config()->set('ngrok-server.runtime_root', $this->runtimeRoot);
        config()->set('ngrok-server.settings_reconcile_interval_ms', 100);
        app(NgrokRuntimeBootstrapper::class)->initialize();
        $this->ngrokSettings->snapshot = new NgrokSettingsSnapshot(
            new NgrokSettingsVersion('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1),
            true,
            true,
            RemoteExternalOrigin::fromString('https://approved.ngrok.app'),
            'supervisor-preflight-token',
            'test',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeRoot);
        parent::tearDown();
    }

    public function test_unavailable_upstream_blocks_before_ngrok_preflight(): void
    {
        Http::fake(fn (): mixed => Http::response([], 503));

        $this->runOneCycle();

        $snapshot = app(NgrokRuntimeSnapshotStore::class)->read();
        self::assertSame('blocked', $snapshot['summary'] ?? null);
        self::assertSame(['upstream_identity_unavailable'], $snapshot['readiness']['reasons'] ?? null);
        self::assertSame('absent', $snapshot['child']['state'] ?? null);
        self::assertSame('clean', app(NgrokLifecycleLedgerStore::class)->read()['state'] ?? null);
        self::assertFalse(is_dir($this->runtimeRoot.DIRECTORY_SEPARATOR.'configs'));
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            self::assertSame('http://127.0.0.1:8000/_local-gpt/runtime-identity', $request->url());
            self::assertNotEmpty($request->header('X-Local-Gpt-Runtime-Nonce'));

            return true;
        });
    }

    public function test_healthy_upstream_reaches_binary_preflight_without_spawning(): void
    {
        config()->set('ngrok-server.binary', '');
        Http::fake(fn (): mixed => Http::response(['protocol_version' => 1, 'mac' => 'valid']));

        $this->runOneCycle();

        $snapshot = app(NgrokRuntimeSnapshotStore::class)->read();
        self::assertSame('error', $snapshot['summary'] ?? null);
        self::assertSame(['The ngrok binary is not configured.'], $snapshot['readiness']['reasons'] ?? null);
        self::assertSame('clean', app(NgrokLifecycleLedgerStore::class)->read()['state'] ?? null);
        Http::assertSentCount(1);
    }

    private function runOneCycle(): void
    {
        $stopChecks = 0;
        self::assertSame(0, app(NgrokSupervisor::class)->run(static function () use (&$stopChecks): bool {
            return $stopChecks++ > 0;
        }));
    }
}
