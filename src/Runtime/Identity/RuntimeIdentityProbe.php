<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Identity;

use Illuminate\Http\Client\Factory;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeIdentityVerifier;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;

final readonly class RuntimeIdentityProbe implements NgrokRuntimeIdentityProbe
{
    public function __construct(private Factory $http, private NgrokRuntimeIdentityVerifier $identity) {}

    public function observe(NgrokLocalUpstream $upstream, string $hostHeader = ''): RuntimeIdentityObservation
    {
        $nonce = random_bytes(32);
        $encoded = $this->identity->encodeNonce($nonce);
        $timeoutSeconds = max(1000, min(30000, (int) config('ngrok-server.runtime_identity_probe_timeout_ms', 5000))) / 1000;
        try {
            $request = $this->http->withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'verify' => $upstream->scheme !== 'https',
            ])->acceptJson()->timeout($timeoutSeconds)
                ->withHeader('X-Agenticus-Mechanicus-Runtime-Nonce', $encoded);
            if ($hostHeader !== '') {
                $request = $request->withHeader('Host', $hostHeader);
            }
            $response = $request->get($upstream->identityUrl());
            $status = $response->status();
            if ($response->serverError() || in_array($status, [408, 425, 429], true)) {
                return RuntimeIdentityObservation::unavailable();
            }
            if (! $response->successful()
                || (int) $response->json('protocol_version') !== $this->identity->protocolVersion()) {
                return RuntimeIdentityObservation::mismatch();
            }
            $mac = $response->json('mac');

            return is_string($mac) && $this->identity->verify($nonce, $mac)
                ? RuntimeIdentityObservation::ready()
                : RuntimeIdentityObservation::mismatch();
        } catch (\Throwable) {
            return RuntimeIdentityObservation::unavailable();
        }
    }

    public function healthy(NgrokLocalUpstream $upstream, string $hostHeader = ''): bool
    {
        return $this->observe($upstream, $hostHeader)->state === RuntimeIdentityObservation::READY;
    }
}
