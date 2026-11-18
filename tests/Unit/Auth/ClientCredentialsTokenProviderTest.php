<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Tests\Unit\Auth;

use DateTimeImmutable;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use Hrabosh\EmaUpd\Auth\AccessTokenProviderInterface;
use Hrabosh\EmaUpd\Auth\ClientCredentials;
use Hrabosh\EmaUpd\Auth\ClientCredentialsTokenProvider;
use Hrabosh\EmaUpd\Exception\AuthenticationException;
use Hrabosh\EmaUpd\Exception\TransportException;
use Hrabosh\EmaUpd\Exception\UpdException;
use Hrabosh\EmaUpd\Tests\Support\MutableClock;
use Hrabosh\EmaUpd\Tests\Support\ScriptedHttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

final class ClientCredentialsTokenProviderTest extends TestCase
{
    private const string ENDPOINT = 'https://login.example.test/tenant/token?audience=public';
    private const string CLIENT_ID = 'synthetic-client+id';
    private const string CLIENT_SECRET = 'synthetic+secret&= with space';
    private const string SCOPE = 'api://synthetic-upd/.default another+scope';
    private const string TOKEN = 'synthetic-opaque-token';

    public function testPostsRegisteredCredentialsAndReusesTokenBeforeTheSafetyBoundary(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-11-18T12:00:00.500000Z'));
        $http = new ScriptedHttpClient(
            self::tokenResponse(self::TOKEN, 600, 'bEaReR'),
            self::tokenResponse('renewed-token', 600),
        );
        $provider = self::provider($http, $clock);

        self::assertSame(0, $http->requestCount());
        self::assertSame(self::TOKEN, $provider->getAccessToken());

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::ENDPOINT, (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $form);
        self::assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'scope' => self::SCOPE,
        ], $form);

        $clock->time = new DateTimeImmutable('2026-11-18T12:09:30.499999Z');
        self::assertSame(self::TOKEN, $provider->getAccessToken());
        self::assertSame(self::TOKEN, $provider->getAccessToken());
        self::assertCount(1, $http->requests);

        $clock->time = new DateTimeImmutable('2026-11-18T12:09:30.500000Z');
        self::assertSame('renewed-token', $provider->getAccessToken());
        self::assertCount(2, $http->requests);
    }

    public function testUsesTheShortTtlAndAcceptsDecimalStrings(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-11-18T12:00:00Z'));
        $http = new ScriptedHttpClient(self::tokenResponse('first-token', '0008'), self::tokenResponse('second-token', 8));
        $provider = self::provider($http, $clock);

        self::assertSame('first-token', $provider->getAccessToken());
        $clock->time = $clock->time->modify('+7 seconds');
        self::assertSame('first-token', $provider->getAccessToken());
        $clock->time = $clock->time->modify('+1 second');
        self::assertSame('second-token', $provider->getAccessToken());
        self::assertCount(2, $http->requests);
    }

    public function testSubtractsTenPercentWhenBelowTheThirtySecondCap(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-11-18T12:00:00Z'));
        $http = new ScriptedHttpClient(self::tokenResponse('first-token', 20), self::tokenResponse('second-token', 20));
        $provider = self::provider($http, $clock);

        self::assertSame('first-token', $provider->getAccessToken());
        $clock->time = $clock->time->modify('+17 seconds');
        self::assertSame('first-token', $provider->getAccessToken());
        $clock->time = $clock->time->modify('+1 second');
        self::assertSame('second-token', $provider->getAccessToken());
        self::assertCount(2, $http->requests);
    }

    public function testDoesNotCacheWithoutAnExpiry(): void
    {
        $http = new ScriptedHttpClient(self::tokenResponse('first-token'), self::tokenResponse('second-token'));
        $provider = self::provider($http);

        self::assertSame('first-token', $provider->getAccessToken());
        self::assertSame('second-token', $provider->getAccessToken());
        self::assertCount(2, $http->requests);
    }

    public function testRejectsAnInvalidBearerValueWithoutCachingIt(): void
    {
        $http = new ScriptedHttpClient(self::tokenResponse('invalid token', 600), self::tokenResponse('usable+/token=', 600));
        $provider = self::provider($http);

        try {
            $provider->getAccessToken();
            self::fail('An invalid Bearer value was accepted.');
        } catch (AuthenticationException) {
            self::assertCount(1, $http->requests);
        }

        self::assertSame('usable+/token=', $provider->getAccessToken());
        self::assertCount(2, $http->requests);
    }

    public function testAllowsARepresentableExpiryWithoutOverflowingTheClock(): void
    {
        $http = new ScriptedHttpClient(self::tokenResponse(self::TOKEN, PHP_INT_MAX));
        $provider = self::provider($http);

        self::assertSame(self::TOKEN, $provider->getAccessToken());
        self::assertSame(self::TOKEN, $provider->getAccessToken());
        self::assertCount(1, $http->requests);
    }

    public function testRejectsATokenThatExpiresDuringTheRequestAndRenewsOnTheNextCall(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-11-18T12:00:00Z'));
        $http = new ScriptedHttpClient(
            static function () use ($clock): Response {
                $clock->time = $clock->time->modify('+18 seconds');

                return self::tokenResponse('expired-token', 20);
            },
            self::tokenResponse('fresh-token', 20),
        );
        $provider = self::provider($http, $clock);

        try {
            $provider->getAccessToken();
            self::fail('An expired token was accepted.');
        } catch (AuthenticationException) {
            self::assertCount(1, $http->requests);
        }
        self::assertSame('fresh-token', $provider->getAccessToken());
        self::assertCount(2, $http->requests);
    }

    #[DataProvider('malformedTokenResponses')]
    public function testRejectsMalformedTokenResponsesWithoutRetrying(string $body): void
    {
        $http = new ScriptedHttpClient(new Response(200, [], $body));
        $provider = self::provider($http);

        $this->expectException(AuthenticationException::class);
        try {
            $provider->getAccessToken();
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedTokenResponses(): iterable
    {
        yield 'invalid JSON' => ['{"access_token"'];
        yield 'non-object JSON' => ['["token"]'];
        yield 'missing token' => ['{"token_type":"Bearer"}'];
        yield 'empty token' => ['{"access_token":"","token_type":"Bearer"}'];
        yield 'blank token' => ['{"access_token":"  ","token_type":"Bearer"}'];
        yield 'numeric token' => ['{"access_token":42,"token_type":"Bearer"}'];
        yield 'missing token type' => ['{"access_token":"token"}'];
        yield 'wrong token type' => ['{"access_token":"token","token_type":"Basic"}'];
        yield 'null expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":null}'];
        yield 'boolean expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":true}'];
        yield 'negative expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":-1}'];
        yield 'zero expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":0}'];
        yield 'zero string expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":"000"}'];
        yield 'float expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":2.0}'];
        yield 'exponent expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":"1e3"}'];
        yield 'malformed string expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":"12s"}'];
        yield 'overflowing expiry' => ['{"access_token":"token","token_type":"Bearer","expires_in":"' . PHP_INT_MAX . '0"}'];
    }

    #[DataProvider('rejectedStatuses')]
    public function testRejectsHttpFailuresWithoutResendingCredentials(int $status): void
    {
        $http = new ScriptedHttpClient(new Response($status, ['Location' => 'https://elsewhere.example.test/'], '{"error":"remote detail"}'));
        $provider = self::provider($http);

        try {
            $provider->getAccessToken();
            self::fail('The token endpoint returned a failure.');
        } catch (AuthenticationException $exception) {
            self::assertInstanceOf(UpdException::class, $exception);
            self::assertStringNotContainsString('remote detail', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertCount(1, $http->requests);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function rejectedStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'redirect that preserves method and body' => [307];
        yield 'server error' => [503];
    }

    public function testTransportErrorsAndDebugOutputDoNotExposeCredentialsOrCachedTokens(): void
    {
        $previousSetting = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previousSetting);

        try {
            $clock = new MutableClock(new DateTimeImmutable('2026-11-18T12:00:00Z'));
            $http = new ScriptedHttpClient(
                self::tokenResponse(self::TOKEN, 1),
                static fn(RequestInterface $request) => throw new RequestException(self::CLIENT_SECRET, $request, new Response(401, [], self::TOKEN)),
            );
            $provider = self::provider($http, $clock);
            $credentials = self::credentials();

            self::assertSame(self::TOKEN, $provider->getAccessToken());
            foreach ([$provider, $credentials] as $object) {
                foreach ([print_r($object, true), var_export($object, true)] as $debug) {
                    foreach ([self::CLIENT_ID, self::CLIENT_SECRET, self::SCOPE, self::TOKEN, 'client_secret='] as $value) {
                        self::assertStringNotContainsString($value, $debug);
                    }
                }
            }

            $clock->time = $clock->time->modify('+1 second');
            try {
                $provider->getAccessToken();
                self::fail('A transport exception was expected.');
            } catch (TransportException $exception) {
                self::assertNull($exception->getPrevious());
                self::assertCount(2, $http->requests);
                $diagnostics = (string) $exception . var_export($exception->getTrace(), true);
                self::assertStringNotContainsString(self::CLIENT_SECRET, $diagnostics);
                self::assertStringNotContainsString(self::TOKEN, $diagnostics);
                self::assertStringNotContainsString('grant_type=client_credentials', $diagnostics);
            }

            try {
                new ClientCredentials(self::CLIENT_ID, self::CLIENT_SECRET, 'http://example.test/token', self::SCOPE);
                self::fail('The configured endpoint was insecure.');
            } catch (InvalidArgumentException $exception) {
                self::assertArrayHasKey('args', $exception->getTrace()[0]);
                foreach ([self::CLIENT_ID, self::CLIENT_SECRET, self::SCOPE] as $value) {
                    self::assertStringNotContainsString($value, (string) $exception . var_export($exception->getTrace(), true));
                }
            }

            $invalidExpiry = new ScriptedHttpClient(self::tokenResponse(self::TOKEN, self::CLIENT_SECRET));
            try {
                self::provider($invalidExpiry)->getAccessToken();
                self::fail('The token expiry was invalid.');
            } catch (AuthenticationException $exception) {
                self::assertNull($exception->getPrevious());
                self::assertStringNotContainsString(self::CLIENT_SECRET, (string) $exception . var_export($exception->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousSetting);
        }
    }

    public function testMalformedSuccessDoesNotExposeTheTokenResponseInItsException(): void
    {
        $http = new ScriptedHttpClient(new Response(200, [], '{"access_token":"' . self::TOKEN . '","token_type":"Basic"}'));

        try {
            self::provider($http)->getAccessToken();
            self::fail('The token type was invalid.');
        } catch (AuthenticationException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, (string) $exception . var_export($exception->getTrace(), true));
        }
    }

    public function testStreamFactoryFailuresDoNotExposeTheRequestForm(): void
    {
        $http = new ScriptedHttpClient();
        $factory = $this->createStub(StreamFactoryInterface::class);
        $factory->method('createStream')->willReturnCallback(
            static fn(string $form) => throw new RuntimeException($form),
        );
        $provider = new ClientCredentialsTokenProvider(self::credentials(), $http, new HttpFactory(), $factory);

        try {
            $provider->getAccessToken();
            self::fail('The factory failed to create the request stream.');
        } catch (TransportException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertSame(0, $http->requestCount());
            self::assertStringNotContainsString('client_secret=', (string) $exception . var_export($exception->getTrace(), true));
        }
    }

    public function testResponseStreamFailuresDoNotExposeTheToken(): void
    {
        $stream = new PumpStream(static fn() => throw new RuntimeException(self::TOKEN));
        $http = new ScriptedHttpClient(new Response(200, [], $stream));

        try {
            self::provider($http)->getAccessToken();
            self::fail('Reading the token response should fail.');
        } catch (AuthenticationException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertCount(1, $http->requests);
            self::assertStringNotContainsString(self::TOKEN, (string) $exception . var_export($exception->getTrace(), true));
        }
    }

    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConfiguration(string $clientId, string $secret, string $endpoint, string $scope): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ClientCredentials($clientId, $secret, $endpoint, $scope);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'client ID' => [' ', self::CLIENT_SECRET, self::ENDPOINT, self::SCOPE];
        yield 'secret' => [self::CLIENT_ID, '', self::ENDPOINT, self::SCOPE];
        yield 'scope' => [self::CLIENT_ID, self::CLIENT_SECRET, self::ENDPOINT, ' '];
        yield 'HTTP' => [self::CLIENT_ID, self::CLIENT_SECRET, 'http://example.test/token', self::SCOPE];
        yield 'relative' => [self::CLIENT_ID, self::CLIENT_SECRET, '/token', self::SCOPE];
        yield 'hostless' => [self::CLIENT_ID, self::CLIENT_SECRET, 'https:///token', self::SCOPE];
        yield 'userinfo' => [self::CLIENT_ID, self::CLIENT_SECRET, 'https://user@example.test/token', self::SCOPE];
        yield 'fragment' => [self::CLIENT_ID, self::CLIENT_SECRET, 'https://example.test/token#', self::SCOPE];
        yield 'malformed percent escape' => [self::CLIENT_ID, self::CLIENT_SECRET, 'https://example.test/%', self::SCOPE];
    }

    public function testProviderInstancesDoNotShareCachedTokens(): void
    {
        $http = new ScriptedHttpClient(self::tokenResponse('first-token', 60), self::tokenResponse('second-token', 60));
        $first = self::provider($http);
        $second = self::provider($http);

        self::assertSame('first-token', $first->getAccessToken());
        self::assertSame('second-token', $second->getAccessToken());
        self::assertSame('first-token', $first->getAccessToken());
        self::assertCount(2, $http->requests);
    }

    public function testProvidesACustomTokenWithoutClientCredentials(): void
    {
        $provider = new class implements AccessTokenProviderInterface {
            public function getAccessToken(): string
            {
                return 'application-managed-token';
            }
        };

        self::assertSame('application-managed-token', $provider->getAccessToken());
    }

    private static function credentials(): ClientCredentials
    {
        return new ClientCredentials(self::CLIENT_ID, self::CLIENT_SECRET, self::ENDPOINT, self::SCOPE);
    }

    private static function provider(ScriptedHttpClient $http, ?ClockInterface $clock = null): ClientCredentialsTokenProvider
    {
        $factory = new HttpFactory();

        return new ClientCredentialsTokenProvider(self::credentials(), $http, $factory, $factory, $clock);
    }

    private static function tokenResponse(string $token, int|string|null $expiry = null, string $type = 'Bearer'): Response
    {
        $payload = ['access_token' => $token, 'token_type' => $type];
        if ($expiry !== null) {
            $payload['expires_in'] = $expiry;
        }

        return new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
