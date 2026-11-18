<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Internal;

use Hrabosh\EmaUpd\Auth\AccessTokenProviderInterface;
use Hrabosh\EmaUpd\Exception\ApiException;
use Hrabosh\EmaUpd\Exception\AuthenticationException;
use Hrabosh\EmaUpd\Exception\TransportException;
use Hrabosh\EmaUpd\Exception\UnexpectedResponseException;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use stdClass;

/** @internal */
final readonly class ApiRequestExecutor
{
    public const string DEFAULT_BASE_URI = 'https://spor.azure-api.net/upd/public/v4/';

    private string $baseUri;
    private string $baseHost;
    private int $basePort;
    private string $basePath;
    private SensitiveParameterValue $dependencies;

    public function __construct(
        #[SensitiveParameter]
        ClientInterface $httpClient,
        #[SensitiveParameter]
        RequestFactoryInterface $requestFactory,
        #[SensitiveParameter]
        AccessTokenProviderInterface $tokenProvider,
        #[SensitiveParameter]
        string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        $parts = self::parseHttpsUri($baseUri);
        if ($parts === null || isset($parts['query']) || !self::isSafePath($parts['path'] ?? '')) {
            throw new InvalidArgumentException('The UPD base URI must be an absolute HTTPS URI with a safe path and no user information, query or fragment.');
        }

        $this->baseUri = rtrim($baseUri, '/') . '/';
        $this->baseHost = strtolower($parts['host']);
        $this->basePort = $parts['port'] ?? 443;
        $this->basePath = self::normalizePath(rtrim($parts['path'] ?? '', '/') . '/');
        // Injected collaborators can retain authenticated messages or tokens.
        $this->dependencies = new SensitiveParameterValue([$httpClient, $requestFactory, $tokenProvider]);
    }

    /**
     * @param list<string> $pathSegments
     * @param array<string, string> $query
     * @return array{payload: DecodedJson, requestUri: UriInterface}
     */
    public function get(
        #[SensitiveParameter]
        array $pathSegments,
        #[SensitiveParameter]
        array $query = [],
    ): array {
        $encodedSegments = [];
        foreach ($pathSegments as $segment) {
            if ($segment === '' || !self::isSafeSegment($segment)) {
                throw new InvalidArgumentException('The UPD request contains an unsafe path segment.');
            }

            // FHIR operation names use a literal dollar sign, which is allowed in a URI path.
            $encodedSegments[] = str_replace('%24', '$', rawurlencode($segment));
        }

        $uri = $this->baseUri . implode('/', $encodedSegments);
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->getAt($uri);
    }

    /**
     * Sends a GET to an absolute URI within the configured API base.
     *
     * @return array{payload: DecodedJson, requestUri: UriInterface}
     */
    public function getAt(#[SensitiveParameter] string $uri): array
    {
        $this->validateDestination($uri);

        /** @var array{ClientInterface, RequestFactoryInterface, AccessTokenProviderInterface} $dependencies */
        $dependencies = $this->dependencies->getValue();
        [$httpClient, $requestFactory, $tokenProvider] = $dependencies;
        try {
            $request = $requestFactory->createRequest('GET', $uri)
                ->withHeader('Accept', 'application/fhir+json');
        } catch (InvalidArgumentException) {
            throw new TransportException('The UPD request could not be prepared.');
        }

        $requestUri = $request->getUri();
        $this->validateDestination((string) $requestUri);
        $token = $tokenProvider->getAccessToken();
        if (preg_match('#\A[A-Za-z0-9._~+/-]+=*\z#', $token) !== 1) {
            throw new AuthenticationException('The token provider did not supply a usable Bearer token.');
        }

        try {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        } catch (InvalidArgumentException) {
            throw new TransportException('The UPD request could not be authenticated.');
        }

        try {
            $response = $httpClient->sendRequest($request);
        } catch (ClientExceptionInterface) {
            throw new TransportException('The UPD request could not be sent.');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ApiException(
                $status,
                $response->hasHeader('Retry-After') ? $response->getHeaderLine('Retry-After') : null,
                self::operationOutcome($response),
            );
        }
        if ($status !== 200) {
            throw new UnexpectedResponseException("The UPD API returned unexpected success status HTTP {$status}.");
        }

        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($mediaType !== 'application/fhir+json' && $mediaType !== 'application/json') {
            throw new UnexpectedResponseException('The UPD API returned an unexpected content type.');
        }

        $payload = self::decodeObject($response);
        if ($payload === null) {
            throw new UnexpectedResponseException('The UPD API response did not contain a readable JSON object.');
        }

        return ['payload' => $payload, 'requestUri' => $requestUri];
    }

    private function validateDestination(#[SensitiveParameter] string $uri): void
    {
        $parts = self::parseHttpsUri($uri);
        $path = $parts['path'] ?? '/';
        if (
            $parts === null
            || strtolower($parts['host']) !== $this->baseHost
            || ($parts['port'] ?? 443) !== $this->basePort
            || !self::isSafePath($path)
            || !str_starts_with(self::normalizePath($path), $this->basePath)
        ) {
            throw new UnexpectedResponseException('The UPD request destination is outside the configured API base or has an unsafe path.');
        }
    }

    /** @return array{host: string, port?: int, path?: string, query?: string}|null */
    private static function parseHttpsUri(#[SensitiveParameter] string $uri): ?array
    {
        $parts = parse_url($uri);
        if (
            filter_var($uri, FILTER_VALIDATE_URL) === false
            || $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/%(?![0-9a-f]{2})/i', $uri) === 1
        ) {
            return null;
        }

        return $parts;
    }

    private static function isSafePath(#[SensitiveParameter] string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (!self::isSafeSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private static function normalizePath(#[SensitiveParameter] string $path): string
    {
        // Compare equivalent URI paths without changing the path or query sent to the API.
        return preg_replace_callback('/%[0-9a-f]{2}/i', static function (#[SensitiveParameter] array $match): string {
            $decoded = rawurldecode($match[0]);

            return preg_match('/[a-z0-9._~-]/i', $decoded) === 1 ? $decoded : strtoupper($match[0]);
        }, $path) ?? $path;
    }

    private static function isSafeSegment(#[SensitiveParameter] string $segment): bool
    {
        // Validate again after decoding to catch nested encodings of traversal and separators.
        do {
            if ($segment === '.' || $segment === '..' || strpbrk($segment, '/\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $segment) === 1) {
                return false;
            }

            $previous = $segment;
            $segment = rawurldecode($segment);
        } while ($segment !== $previous);

        return true;
    }

    private static function decodeObject(#[SensitiveParameter] ResponseInterface $response): ?DecodedJson
    {
        try {
            return DecodedJson::fromString((string) $response->getBody());
        } catch (JsonException|RuntimeException) {
            return null;
        }
    }

    /** @return array<int|string, mixed>|null */
    private static function operationOutcome(#[SensitiveParameter] ResponseInterface $response): ?array
    {
        $decoded = self::decodeObject($response);
        if ($decoded === null) {
            return null;
        }

        $payload = $decoded->getObject();
        $issues = $payload->issue ?? null;
        if (($payload->resourceType ?? null) !== 'OperationOutcome' || !is_array($issues) || $issues === []) {
            return null;
        }

        foreach ($issues as $issue) {
            if (!$issue instanceof stdClass) {
                return null;
            }

            $severity = $issue->severity ?? null;
            $code = $issue->code ?? null;
            if (!in_array($severity, ['fatal', 'error', 'warning', 'information'], true) || !is_string($code) || trim($code) === '') {
                return null;
            }
        }

        return $decoded->toArray();
    }
}
