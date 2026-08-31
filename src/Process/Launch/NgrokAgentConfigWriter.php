<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Process\Launch;

use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;

final readonly class NgrokAgentConfigWriter
{
    public function __construct(private NgrokRuntimePaths $paths, private DurableRuntimeFile $files) {}

    /** @return array{path: string, relative_path: string, sha256: string} */
    public function write(string $spawnAttemptId, int $agentApiPort): array
    {
        $path = $this->paths->config($spawnAttemptId);
        $content = <<<YAML
version: 3
agent:
  web_addr: 127.0.0.1:{$agentApiPort}
  web_allow_hosts:
    - 127.0.0.1
    - localhost
  remote_management: false
  update_check: false
  inspect_db_size: -1
  console_ui: false
  log: false
YAML;
        $content .= "\n";
        $this->files->createTextExclusive($path, $content);

        return ['path' => $path, 'relative_path' => $this->paths->relativeToRoot($path), 'sha256' => hash('sha256', $content)];
    }
}
