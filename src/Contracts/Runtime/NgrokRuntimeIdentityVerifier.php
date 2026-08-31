<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

interface NgrokRuntimeIdentityVerifier
{
    public function protocolVersion(): int;

    public function encodeNonce(string $nonce): string;

    public function verify(string $nonce, string $signature): bool;
}
