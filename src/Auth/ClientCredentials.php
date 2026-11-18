<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Auth;

use InvalidArgumentException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class ClientCredentials
{
    private SensitiveParameterValue $registration;

    public function __construct(
        #[SensitiveParameter]
        string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        #[SensitiveParameter]
        string $tokenEndpoint,
        #[SensitiveParameter]
        string $scope,
    ) {
        if (trim($clientId) === '' || trim($clientSecret) === '' || trim($scope) === '') {
            throw new InvalidArgumentException('The client ID, client secret and scope must be non-empty.');
        }

        $parts = parse_url($tokenEndpoint);
        if (
            filter_var($tokenEndpoint, FILTER_VALIDATE_URL) === false
            || $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/%(?![0-9a-f]{2})/i', $tokenEndpoint) === 1
        ) {
            throw new InvalidArgumentException('The token endpoint must be an absolute HTTPS URL without user information or a fragment.');
        }

        $this->registration = new SensitiveParameterValue([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'tokenEndpoint' => $tokenEndpoint,
            'scope' => $scope,
        ]);
    }

    public function getClientId(): string
    {
        return $this->registration()['clientId'];
    }

    public function getClientSecret(): string
    {
        return $this->registration()['clientSecret'];
    }

    public function getTokenEndpoint(): string
    {
        return $this->registration()['tokenEndpoint'];
    }

    public function getScope(): string
    {
        return $this->registration()['scope'];
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return array{clientId: string, clientSecret: string, tokenEndpoint: string, scope: string} */
    private function registration(): array
    {
        /** @var array{clientId: string, clientSecret: string, tokenEndpoint: string, scope: string} $registration */
        $registration = $this->registration->getValue();

        return $registration;
    }
}
