<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Runtime\Identity;

use Illuminate\Http\Client\Factory;
use SimoneBianco\LaravelNgrokServer\Contracts\Runtime\NgrokRuntimeIdentityVerifier;
use SimoneBianco\LaravelNgrokServer\ValueObjects\NgrokLocalUpstream;

final readonly class RuntimeIdentityProbe
{
    public function __construct(private Factory $http, private NgrokRuntimeIdentityVerifier $identity) {}

    public function healthy(NgrokLocalUpstream $upstream, string $hostHeader = ''): bool
    {
        $nonce = random_bytes(32);
        $encoded = $this->identity->encodeNonce($nonce);
        try {
            $request = $this->http->withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'verify' => $upstream->scheme !== 'https',
            ])->acceptJson()->timeout(2)
                ->withHeader('X-Local-Gpt-Runtime-Nonce', $encoded);
            if ($hostHeader !== '') {
                $request = $request->withHeader('Host', $hostHeader);
            }
            $response = $request->get($upstream->identityUrl());
            if (! $response->successful() || (int) $response->json('protocol_version') !== $this->identity->protocolVersion()) {
                return false;
            }
            $mac = $response->json('mac');

            return is_string($mac) && $this->identity->verify($nonce, $mac);
        } catch (\Throwable) {
            return false;
        }
    }
}
