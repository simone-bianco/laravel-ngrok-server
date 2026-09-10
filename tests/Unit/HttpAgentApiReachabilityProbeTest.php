<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimoneBianco\LaravelNgrokServer\Process\Supervisor\HttpAgentApiReachabilityProbe;

final class HttpAgentApiReachabilityProbeTest extends TestCase
{
    private mixed $previousHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousHttp = Http::getFacadeRoot();
        Http::swap(new Factory);
    }

    protected function tearDown(): void
    {
        if ($this->previousHttp !== null) {
            Http::swap($this->previousHttp);
        } else {
            Http::clearResolvedInstance(Factory::class);
        }
        parent::tearDown();
    }

    public function test_real_http_transport_recognizes_a_port_without_a_listener(): void
    {
        // Reserve an ephemeral port without listening, so another process cannot take it.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
        self::assertIsResource($socket);

        try {
            $address = 'http://'.stream_socket_get_name($socket, false);
            self::assertTrue((new HttpAgentApiReachabilityProbe)->unavailable($address));
        } finally {
            fclose($socket);
        }
    }

    public function test_real_http_timeout_preserves_a_live_listener(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket);

        try {
            $address = 'http://'.stream_socket_get_name($socket, false);
            self::assertFalse((new HttpAgentApiReachabilityProbe)->unavailable($address));
        } finally {
            fclose($socket);
        }
    }

    public function test_errno_seven_is_unavailable(): void
    {
        self::assertTrue($this->probe(7)->unavailable('http://127.0.0.1:4041'));
    }

    public function test_timeout_without_listener_is_unavailable(): void
    {
        self::assertTrue($this->probe(28, false)->unavailable('http://127.0.0.1:4041'));
    }

    public function test_timeout_with_listener_is_not_unavailable(): void
    {
        self::assertFalse($this->probe(28, true)->unavailable('http://127.0.0.1:4041'));
    }

    public function test_other_transport_errno_and_exception_fail_closed(): void
    {
        self::assertFalse($this->probe(6)->unavailable('http://127.0.0.1:4041'));
        self::assertFalse((new HttpAgentApiReachabilityProbe(static fn (): never => throw new RuntimeException('unexpected'), static fn (): bool => false))->unavailable('http://127.0.0.1:4041'));
    }

    public function test_successful_response_is_not_unavailable(): void
    {
        self::assertFalse((new HttpAgentApiReachabilityProbe(static fn (): null => null, static fn (): bool => false))->unavailable('http://127.0.0.1:4041'));
    }

    public function test_http_error_response_is_not_unavailable(): void
    {
        self::assertFalse((new HttpAgentApiReachabilityProbe(static fn (): null => null, static fn (): bool => false))->unavailable('http://127.0.0.1:4041'));
    }

    public function test_non_loopback_and_malformed_addresses_fail_closed_without_transport(): void
    {
        $probe = new HttpAgentApiReachabilityProbe(static fn (): never => throw new RuntimeException('transport must not run'));

        self::assertFalse($probe->unavailable('http://example.test:4041'));
        self::assertFalse($probe->unavailable('not an address'));
    }

    private function probe(int $errno, bool $listener = false): HttpAgentApiReachabilityProbe
    {
        return new HttpAgentApiReachabilityProbe(static fn (): int => $errno, static fn (): bool => $listener);
    }
}
