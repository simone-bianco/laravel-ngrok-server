<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokHostHeaderPolicy;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;

final readonly class NgrokAgentConfigWriter
{
    public function __construct(private NgrokRuntimePaths $paths, private DurableRuntimeFile $files) {}

    /** @return array{path: string, relative_path: string, sha256: string} */
    public function write(
        string $spawnAttemptId,
        int $agentApiPort,
        string $endpointInstanceId,
        string $supervisorInstanceId,
        RemoteExternalOrigin $origin,
        NgrokLocalUpstream $upstream,
        string $upstreamHostHeader,
    ): array {
        $path = $this->paths->config($spawnAttemptId);
        // JSON is valid YAML. One immutable, hashed artifact owns both agent
        // configuration and the endpoint policy, including reconnects.
        $content = json_encode([
            'version' => 3,
            'agent' => [
                'web_addr' => '127.0.0.1:'.$agentApiPort,
                'web_allow_hosts' => ['127.0.0.1', 'localhost'],
                'remote_management' => false,
                'update_check' => false,
                'inspect_db_size' => -1,
                'console_ui' => false,
                'log' => false,
            ],
            'endpoints' => [[
                'name' => $endpointInstanceId,
                'url' => $origin->value(),
                'metadata' => json_encode([
                    'local_gpt_endpoint_instance_id' => $endpointInstanceId,
                    'local_gpt_supervisor_instance_id' => $supervisorInstanceId,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'upstream' => ['url' => $upstream->value()],
                'inspect' => false,
                'pooling_enabled' => false,
                'traffic_policy' => NgrokHostHeaderPolicy::definition($upstreamHostHeader),
            ]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $this->files->createTextExclusive($path, $content);

        return ['path' => $path, 'relative_path' => $this->paths->relativeToRoot($path), 'sha256' => hash('sha256', $content)];
    }
}
