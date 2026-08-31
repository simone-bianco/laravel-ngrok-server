<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\ValueObjects;

final readonly class NgrokSettingsSnapshot
{
    public function __construct(
        public NgrokSettingsVersion $version,
        public bool $remoteAccessEnabled,
        public bool $desiredEnabled,
        public ?RemoteExternalOrigin $externalOrigin,
        public ?string $authtoken,
        public string $authtokenSource,
        public bool $directWebEnabled = true,
        public bool $directApiEnabled = true,
        public bool $ngrokWebEnabled = true,
        public bool $ngrokApiEnabled = true,
    ) {}

    public function remoteValuesEqual(self $other): bool
    {
        return $this->remoteAccessEnabled === $other->remoteAccessEnabled
            && $this->desiredEnabled === $other->desiredEnabled
            && $this->externalOrigin?->value() === $other->externalOrigin?->value()
            && hash_equals($this->authtoken ?? '', $other->authtoken ?? '')
            && $this->authtokenSource === $other->authtokenSource
            && $this->directWebEnabled === $other->directWebEnabled
            && $this->directApiEnabled === $other->directApiEnabled
            && $this->ngrokWebEnabled === $other->ngrokWebEnabled
            && $this->ngrokApiEnabled === $other->ngrokApiEnabled;
    }
}
