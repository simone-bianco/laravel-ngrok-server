<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiClient;
use SimoneBianco\LaravelNgrokServer\AgentApi\NgrokAgentApiObservation;
use SimoneBianco\LaravelNgrokServer\Process\Binary\NgrokBinary;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokAgentConfigWriter;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparation;
use SimoneBianco\LaravelNgrokServer\Process\Ownership\NgrokProcessOwnership;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\DurableRuntimeFile;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\NgrokRuntimePaths;
use SimoneBianco\LaravelNgrokServer\Runtime\Filesystem\WindowsAtomicFileReplacer;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokHostHeaderPolicy;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsSnapshot;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokSettingsVersion;
use SimoneBianco\LaravelNgrokServer\ValueObjects\RemoteExternalOrigin;
use Symfony\Component\Yaml\Yaml;

final class NgrokEndpointPolicyTest extends TestCase
{
    public function test_endpoint_policy_survives_config_serialization_and_belongs_to_the_hashed_artifact(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $root = dirname(__DIR__, 5).'/storage/framework/testing/ngrok-policy-'.bin2hex(random_bytes(8));
        $container->instance('config', new Repository(['ngrok-server' => ['runtime_root' => $root]]));
        try {
            $launch = $this->launch($root);
            $writer = new NgrokAgentConfigWriter(new NgrokRuntimePaths, new DurableRuntimeFile(new WindowsAtomicFileReplacer));
            $artifact = $writer->write($launch->spawnAttemptId, $launch->agentApiPort, $launch->endpointInstanceId, $launch->supervisorInstanceId, $launch->origin, $launch->upstream, $launch->upstreamHostHeader);
            $config = Yaml::parseFile($artifact['path']);
            self::assertSame(hash_file('sha256', $artifact['path']), $artifact['sha256']);
            self::assertSame('configs/'.$launch->spawnAttemptId.'.yml', $artifact['relative_path']);
            self::assertCount(1, $config['endpoints']);
            $endpoint = $config['endpoints'][0];
            self::assertSame($launch->endpointInstanceId, $endpoint['name']);
            self::assertSame($launch->origin->value(), $endpoint['url']);
            self::assertSame($launch->upstream->value(), $endpoint['upstream']['url']);
            self::assertFalse($endpoint['inspect']);
            self::assertFalse($endpoint['pooling_enabled']);
            self::assertSame('agenticus-mechanicus.test', $endpoint['traffic_policy']['on_http_request'][0]['actions'][0]['config']['headers']['host']);
            self::assertArrayNotHasKey('authtoken', $config['agent']);
            self::assertStringNotContainsString('test-secret', file_get_contents($artifact['path']));
            self::assertSame($launch->supervisorInstanceId, json_decode($endpoint['metadata'], true)['local_gpt_supervisor_instance_id']);
            $this->expectException(\RuntimeException::class);
            $writer->write($launch->spawnAttemptId, $launch->agentApiPort, $launch->endpointInstanceId, $launch->supervisorInstanceId, $launch->origin, $launch->upstream, 'other.test');
        } finally {
            (new Filesystem)->deleteDirectory($root);
            Container::setInstance($previous);
        }
    }

    public function test_online_endpoint_cannot_be_ready_when_host_policy_disappears_or_changes(): void
    {
        $launch = $this->launch(__DIR__);
        $http = new Factory;
        $policy = NgrokHostHeaderPolicy::definition($launch->upstreamHostHeader);
        $endpoint = [
            'name' => $launch->endpointInstanceId,
            'url' => $launch->origin->value(),
            'upstream' => ['url' => $launch->upstream->value()],
            'inspect' => false,
            'pooling_enabled' => false,
            'traffic_policy' => Yaml::dump($policy, 10),
        ];
        $missing = $endpoint;
        unset($missing['traffic_policy']);
        $wrongHost = $endpoint;
        $wrongHost['traffic_policy'] = Yaml::dump(NgrokHostHeaderPolicy::definition('other.test'), 10);
        $extraAction = $policy;
        $extraAction['on_http_request'][0]['actions'][] = ['type' => 'custom-response', 'config' => ['status_code' => 200]];
        $http->fakeSequence()
            ->push(['endpoints' => [$endpoint]])
            ->push(['endpoints' => [$missing]])
            ->push(['endpoints' => [$wrongHost]])
            ->push(['endpoints' => [array_replace($endpoint, ['traffic_policy' => Yaml::dump($extraAction, 10)])]])
            ->push(['endpoints' => [array_replace($endpoint, ['traffic_policy' => 'on_http_request: [invalid'])]])
            ->push(['endpoints' => [$endpoint]]);
        $client = new NgrokAgentApiClient($http);
        self::assertSame(NgrokAgentApiObservation::READY, $client->observe($launch)->state);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(NgrokAgentApiObservation::MISMATCH, $client->observe($launch)->state);
        }
        self::assertSame(NgrokAgentApiObservation::READY, $client->observe($launch)->state);
    }

    public function test_host_policy_rejects_header_injection_and_empty_hosts(): void
    {
        foreach (['', "app.test\r\nAuthorization: secret", 'https://app.test', 'app.test/path'] as $host) {
            try {
                NgrokHostHeaderPolicy::definition($host);
                self::fail('Invalid Host was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function launch(string $root): NgrokLaunchPreparation
    {
        $origin = RemoteExternalOrigin::fromString('https://approved.ngrok.app');

        return new NgrokLaunchPreparation(
            spawnAttemptId: str_repeat('a', 32), endpointInstanceId: str_repeat('b', 32),
            supervisorInstanceId: str_repeat('c', 32), bootEpochId: str_repeat('d', 32),
            ownership: new NgrokProcessOwnership(NgrokProcessOwnership::POSIX_PROCESS, null),
            binary: new NgrokBinary('ngrok', 'ngrok version 3'), origin: $origin,
            upstream: NgrokLocalUpstream::fromString('https://127.0.0.1:443'), agentApiPort: 4041,
            agentApiAddress: 'http://127.0.0.1:4041', configPath: $root.'/config.yml',
            configRelativePath: 'configs/'.str_repeat('a', 32).'.yml', configSha256: hash('sha256', 'config'), environment: [],
            settings: new NgrokSettingsSnapshot(new NgrokSettingsVersion('00000000000000000000000000', 1), true, true, $origin, 'test-secret', 'test'),
            upstreamHostHeader: 'agenticus-mechanicus.test',
        );
    }
}
