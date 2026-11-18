<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Auth;

use DateTimeImmutable;
use Hrabosh\EmaUpd\Exception\AuthenticationException;
use Hrabosh\EmaUpd\Exception\TransportException;
use Hrabosh\EmaUpd\Internal\BearerToken;
use Hrabosh\EmaUpd\Internal\SystemClock;
use InvalidArgumentException;
use JsonException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;

final class ClientCredentialsTokenProvider implements AccessTokenProviderInterface
{
    private readonly ClockInterface $clock;
    private readonly SensitiveParameterValue $http;

    /** @var array{token: SensitiveParameterValue, startedAt: DateTimeImmutable, usableSeconds: int}|null */
    private ?array $cache = null;

    public function __construct(
        #[SensitiveParameter]
        private readonly ClientCredentials $credentials,
        #[SensitiveParameter]
        ClientInterface $httpClient,
        #[SensitiveParameter]
        RequestFactoryInterface $requestFactory,
        #[SensitiveParameter]
        StreamFactoryInterface $streamFactory,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        // A transport or factory can retain credential-bearing messages.
        $this->http = new SensitiveParameterValue([$httpClient, $requestFactory, $streamFactory]);
    }

    /**
     * @phpstan-impure
     *
     * @throws AuthenticationException When the token endpoint rejects the request or sends an unusable response.
     * @throws TransportException When the HTTP dependencies cannot prepare or send the request.
     */
    public function getAccessToken(): string
    {
        $cache = $this->cache;
        if ($cache !== null && self::isBeforeExpiry($cache['startedAt'], $cache['usableSeconds'], $this->clock->now())) {
            /** @var string $token The cache stores only a validated token string. */
            $token = $cache['token']->getValue();

            return $token;
        }

        $this->cache = null;
        /** @var array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface} $http */
        $http = $this->http->getValue();
        [$httpClient, $requestFactory, $streamFactory] = $http;
        $form = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->credentials->getClientId(),
            'client_secret' => $this->credentials->getClientSecret(),
            'scope' => $this->credentials->getScope(),
        ], '', '&', PHP_QUERY_RFC1738);
        try {
            $request = $requestFactory->createRequest('POST', $this->credentials->getTokenEndpoint())
                ->withHeader('Accept', 'application/json')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($streamFactory->createStream($form));
        } catch (InvalidArgumentException|RuntimeException) {
            throw new TransportException('The token request could not be prepared.');
        }

        $startedAt = $this->clock->now();
        try {
            $response = $httpClient->sendRequest($request);
        } catch (ClientExceptionInterface) {
            throw new TransportException('The token request could not be sent.');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new AuthenticationException("The token endpoint returned HTTP {$status}.");
        }

        try {
            $body = (string) $response->getBody();
        } catch (RuntimeException) {
            throw new AuthenticationException('The token response body could not be read.');
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuthenticationException('The token endpoint returned invalid JSON.');
        }

        if (!is_array($payload)) {
            throw new AuthenticationException('The token endpoint returned an unexpected JSON payload.');
        }

        $token = $payload['access_token'] ?? null;
        $type = $payload['token_type'] ?? null;
        if (!is_string($token) || !BearerToken::isValid($token) || !is_string($type) || strcasecmp($type, 'Bearer') !== 0) {
            throw new AuthenticationException('The token endpoint did not return a usable Bearer token.');
        }

        if (array_key_exists('expires_in', $payload)) {
            $ttl = self::parseExpiry($payload['expires_in']);
            $usableSeconds = $ttl - min(30, intdiv($ttl, 10));
            if (!self::isBeforeExpiry($startedAt, $usableSeconds, $this->clock->now())) {
                throw new AuthenticationException('The token had reached its usable expiry before it could be returned.');
            }

            $this->cache = [
                'token' => new SensitiveParameterValue($token),
                'startedAt' => $startedAt,
                'usableSeconds' => $usableSeconds,
            ];
        }

        return $token;
    }

    /** @return array{hasCachedToken: bool} */
    public function __debugInfo(): array
    {
        return ['hasCachedToken' => $this->cache !== null];
    }

    /** @return positive-int */
    private static function parseExpiry(#[SensitiveParameter] mixed $expiry): int
    {
        if (is_string($expiry) && preg_match('/\A[0-9]+\z/', $expiry) === 1) {
            $expiry = filter_var(ltrim($expiry, '0'), FILTER_VALIDATE_INT);
        }

        if (!is_int($expiry) || $expiry <= 0) {
            throw new AuthenticationException('The token endpoint returned an invalid expiry.');
        }

        return $expiry;
    }

    private static function isBeforeExpiry(DateTimeImmutable $startedAt, int $usableSeconds, DateTimeImmutable $now): bool
    {
        // Compare elapsed time to avoid overflowing on a large TTL or losing the subsecond boundary.
        $elapsedSeconds = $now->getTimestamp() - $startedAt->getTimestamp();

        return $elapsedSeconds < $usableSeconds
            || ($elapsedSeconds === $usableSeconds && $now->format('u') < $startedAt->format('u'));
    }
}
