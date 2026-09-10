<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Supervisor;

use Closure;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpAgentApiReachabilityProbe implements AgentApiReachabilityProbe
{
    /** @var Closure(string): ?int */
    private Closure $transport;

    /** @var Closure(string): bool */
    private Closure $listenerExists;

    public function __construct(?Closure $transport = null, ?Closure $listenerExists = null)
    {
        $this->transport = $transport ?? static function (string $address): ?int {
            $errno = null;

            try {
                Http::withOptions([
                    'allow_redirects' => false,
                    'proxy' => '',
                    'on_stats' => static function (TransferStats $stats) use (&$errno): void {
                        $errno = $stats->getHandlerErrorData();
                    },
                ])->connectTimeout(1)->timeout(1)->acceptJson()->get(rtrim($address, '/').'/api/endpoints');
            } catch (ConnectionException) {
                return is_int($errno) ? $errno : null;
            }

            return null;
        };
        $this->listenerExists = $listenerExists ?? $this->defaultListenerExists(...);
    }

    public function unavailable(string $address): bool
    {
        if (! $this->isLoopbackAddress($address)) {
            return false;
        }
        try {
            $errno = ($this->transport)($address);

            return $errno === 7 || ($errno === 28 && ! ($this->listenerExists)($address));
        } catch (\Throwable) {
            return false;
        }
    }

    private function defaultListenerExists(string $address): bool
    {
        if (! $this->isLoopbackAddress($address)) {
            return false;
        }
        $parts = parse_url($address);
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$parts['port'], $code, $message, 0.2, STREAM_CLIENT_CONNECT);
        if (! is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function isLoopbackAddress(string $address): bool
    {
        $parts = parse_url($address);

        return is_array($parts) && ($parts['host'] ?? null) === '127.0.0.1' && is_int($parts['port'] ?? null);
    }
}
