<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\AgentApi;

use Illuminate\Http\Client\Factory;
use SimoneBianco\LaravelNgrokServer\Process\Launch\NgrokLaunchPreparation;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokHostHeaderPolicy;

final readonly class NgrokAgentApiClient
{
    public function __construct(private Factory $http) {}

    public function observe(NgrokLaunchPreparation $launch): NgrokAgentApiObservation
    {
        try {
            $response = $this->http->withOptions(['allow_redirects' => false, 'proxy' => ''])
                ->acceptJson()
                ->timeout(2)
                ->get($launch->agentApiAddress.'/api/endpoints');
            if (! $response->successful()) {
                return NgrokAgentApiObservation::unavailable();
            }
            $endpoints = $response->json('endpoints');
            if (! is_array($endpoints)) {
                return NgrokAgentApiObservation::mismatch();
            }
            $matching = array_values(array_filter(
                $endpoints,
                static fn (mixed $endpoint): bool => is_array($endpoint)
                    && ($endpoint['name'] ?? null) === $launch->endpointInstanceId,
            ));
            if ($matching === []) {
                return NgrokAgentApiObservation::unavailable();
            }
            if (count($matching) !== 1) {
                return NgrokAgentApiObservation::mismatch();
            }
            $endpoint = $matching[0];
            $upstream = data_get($endpoint, 'upstream.url');
            if (($endpoint['url'] ?? null) !== $launch->origin->value()
                || $upstream !== $launch->upstream->value()
                || ($endpoint['inspect'] ?? false) !== false
                || ($endpoint['pooling_enabled'] ?? false) !== false
                || ! NgrokHostHeaderPolicy::matches($endpoint['traffic_policy'] ?? null, $launch->upstreamHostHeader)) {
                return NgrokAgentApiObservation::mismatch();
            }

            return NgrokAgentApiObservation::ready($endpoint);
        } catch (\Throwable) {
            return NgrokAgentApiObservation::unavailable();
        }

        return NgrokAgentApiObservation::unavailable();
    }

    /** @return array<string, mixed>|null */
    public function endpoint(NgrokLaunchPreparation $launch): ?array
    {
        return $this->observe($launch)->endpoint;
    }
}
