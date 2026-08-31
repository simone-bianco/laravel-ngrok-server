<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use RuntimeException;

final class NgrokAgentApiPortAllocator
{
    public function allocate(array $excluded = []): int
    {
        $minimum = max(1024, (int) config('ngrok-server.agent_api_port_min', 4041));
        $maximum = min(65535, (int) config('ngrok-server.agent_api_port_max', 4099));
        if ($maximum < $minimum) {
            throw new RuntimeException('The ngrok Agent API port range is invalid.');
        }
        foreach (range($minimum, $maximum) as $port) {
            if (in_array($port, $excluded, true)) {
                continue;
            }
            $errno = 0;
            $error = '';
            $socket = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
            if (is_resource($socket)) {
                fclose($socket);

                return $port;
            }
        }

        throw new RuntimeException('No loopback port is available for the ngrok Agent API.');
    }
}
