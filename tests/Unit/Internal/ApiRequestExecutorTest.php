<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Tests\Unit\Internal;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use Hrabosh\EmaUpd\Auth\AccessTokenProviderInterface;
use Hrabosh\EmaUpd\Exception\ApiException;
use Hrabosh\EmaUpd\Exception\AuthenticationException;
use Hrabosh\EmaUpd\Exception\TransportException;
use Hrabosh\EmaUpd\Exception\UnexpectedResponseException;
use Hrabosh\EmaUpd\Internal\ApiRequestExecutor;
use Hrabosh\EmaUpd\Tests\Support\ScriptedHttpClient;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class ApiRequestExecutorTest extends TestCase
{
    private const string TOKEN = 'synthetic-api-token';
    private const string PATH = 'https://spor.azure-api.net/upd/public/v4/MedicinalProductDefinition';

    public function testSendsOneAuthenticatedGetAndPreservesTheDecodedObject(): void
    {
        $http = new ScriptedHttpClient(new Response(
            200,
            ['Content-Type' => 'Application/FHIR+JSON; charset=utf-8'],
            '{"resourceType":"MedicinalProductDefinition","id":"000123","extension":[{"url":"synthetic","valueInteger":9223372036854775808}]}',
        ));
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->once())->method('getAccessToken')->willReturn(self::TOKEN);
        $executor = new ApiRequestExecutor($http, new HttpFactory(), $tokens);

        $result = $executor->get(['MedicinalProductDefinition', '000123']);

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::PATH . '/000123', (string) $request->getUri());
        self::assertSame(['application/fhir+json'], $request->getHeader('Accept'));
        self::assertSame(['Bearer ' . self::TOKEN], $request->getHeader('Authorization'));
        self::assertSame((string) $request->getUri(), (string) $result['requestUri']);
        self::assertSame([
            'resourceType' => 'MedicinalProductDefinition',
            'id' => '000123',
            'extension' => [['url' => 'synthetic', 'valueInteger' => '9223372036854775808']],
        ], $result['payload']);
    }

    public function testEncodesPathAndQueryComponentsExactlyOnceAndNormalizesTheBase(): void
    {
        $http = new ScriptedHttpClient(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $executor = $this->executor($http, 'https://uat.example.test:8443/upd/public/v4///');

        $result = $executor->get(['MedicinalProductDefinition', 'a?b#c+d', '$everything'], ['a+b' => 'a b+&č', 'key' => '10%']);

        self::assertSame(
            'https://uat.example.test:8443/upd/public/v4/MedicinalProductDefinition/a%3Fb%23c%2Bd/$everything?a%2Bb=a%20b%2B%26%C4%8D&key=10%25',
            (string) $http->requests[0]->getUri(),
        );
        self::assertSame([], $result['payload']);
    }

    public function testPreservesTheLiteralFhirOperationPath(): void
    {
        $http = new ScriptedHttpClient(new Response(200, ['Content-Type' => 'application/fhir+json'], '{}'));

        $this->executor($http)->get(['MedicinalProductDefinition', '000123', '$everything']);

        self::assertSame(self::PATH . '/000123/$everything', (string) $http->requests[0]->getUri());
    }

    #[DataProvider('invalidBaseUris')]
    public function testRejectsUnsafeBaseUriBeforeAcquiringAToken(string $uri): void
    {
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');

        $this->expectException(InvalidArgumentException::class);
        new ApiRequestExecutor(new ScriptedHttpClient(), new HttpFactory(), $tokens, $uri);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBaseUris(): iterable
    {
        yield 'HTTP' => ['http://example.test/upd'];
        yield 'relative' => ['/upd/public/v4'];
        yield 'missing host' => ['https:///upd'];
        yield 'userinfo' => ['https://user@example.test/upd'];
        yield 'empty userinfo' => ['https://@example.test/upd'];
        yield 'query' => ['https://example.test/upd?key=1'];
        yield 'empty fragment' => ['https://example.test/upd#'];
        yield 'traversal' => ['https://example.test/upd/../outside'];
        yield 'encoded separator' => ['https://example.test/upd%2foutside'];
        yield 'malformed escape' => ['https://example.test/upd/%'];
    }

    #[DataProvider('unsafeSegments')]
    public function testRejectsUnsafePathSegmentBeforeAcquiringAToken(string $segment): void
    {
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');
        $executor = new ApiRequestExecutor(new ScriptedHttpClient(), new HttpFactory(), $tokens);

        $this->expectException(InvalidArgumentException::class);
        $executor->get(['MedicinalProductDefinition', $segment]);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeSegments(): iterable
    {
        yield 'empty' => [''];
        yield 'slash' => ['other/path'];
        yield 'backslash' => ['other\\path'];
        yield 'dot segment' => ['.'];
        yield 'parent segment' => ['..'];
        yield 'encoded traversal' => ['%252e%252e'];
        yield 'encoded slash' => ['some%2Fother'];
        yield 'control' => ["id\x00"];
    }

    #[DataProvider('unsafeDestinations')]
    public function testSharedDestinationCheckRunsBeforeAcquiringAToken(string $uri): void
    {
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');
        $executor = new ApiRequestExecutor(new ScriptedHttpClient(), new HttpFactory(), $tokens);

        $this->expectException(UnexpectedResponseException::class);
        $executor->getAt($uri);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeDestinations(): iterable
    {
        yield 'other host' => ['https://elsewhere.example.test/upd/public/v4/MedicinalProductDefinition'];
        yield 'deceptive host' => ['https://spor.azure-api.net.example.test/upd/public/v4/MedicinalProductDefinition'];
        yield 'wrong port' => ['https://spor.azure-api.net:8443/upd/public/v4/MedicinalProductDefinition'];
        yield 'scheme downgrade' => ['http://spor.azure-api.net/upd/public/v4/MedicinalProductDefinition'];
        yield 'path prefix bypass' => ['https://spor.azure-api.net/upd/public/v40/MedicinalProductDefinition'];
        yield 'nested traversal' => ['https://spor.azure-api.net/upd/public/v4/%25%32%65%25%32%65/outside'];
        yield 'encoded path separator' => ['https://spor.azure-api.net/upd/public/v4/resource%2Foutside'];
        yield 'userinfo' => ['https://user@spor.azure-api.net/upd/public/v4/MedicinalProductDefinition'];
        yield 'fragment' => [self::PATH . '#'];
    }

    public function testAcceptsTheSameHttpsOriginAndPreservesAnExistingQuery(): void
    {
        $http = new ScriptedHttpClient(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $uri = 'https://SPOR.AZURE-API.NET:443/upd/public/v4/MedicinalProductDefinition?_ct=a%2B%2F%3D';
        $result = $this->executor($http)->getAt($uri);

        self::assertSame(self::PATH . '?_ct=a%2B%2F%3D', (string) $result['requestUri']);
        self::assertCount(1, $http->requests);
    }

    public function testComparesEquivalentBasePathsWithoutRewritingTheRequestUri(): void
    {
        $http = new ScriptedHttpClient(
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
        );
        $tokens = $this->createStub(AccessTokenProviderInterface::class);
        $tokens->method('getAccessToken')->willReturn(self::TOKEN);
        $factory = new HttpFactory();
        $uri = 'https://spor.azure-api.net/upd/public/%764/MedicinalProductDefinition?_ct=a%2B';

        $normalBase = new ApiRequestExecutor($http, $factory, $tokens);
        self::assertSame($uri, (string) $normalBase->getAt($uri)['requestUri']);

        $encodedBase = new ApiRequestExecutor($http, $factory, $tokens, 'https://spor.azure-api.net/upd/public/%764/');
        self::assertSame(self::PATH, (string) $encodedBase->getAt(self::PATH)['requestUri']);
        self::assertCount(2, $http->requests);
    }

    #[DataProvider('invalidTokens')]
    public function testRejectsInvalidBearerValuesBeforeSending(string $token): void
    {
        $http = new ScriptedHttpClient();
        $provider = $this->createStub(AccessTokenProviderInterface::class);
        $provider->method('getAccessToken')->willReturn($token);
        $executor = new ApiRequestExecutor($http, new HttpFactory(), $provider);

        $this->expectException(AuthenticationException::class);
        try {
            $executor->getAt(self::PATH);
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'embedded space' => ['two tokens'];
        yield 'header injection' => ["some\r\nLocation: https://elsewhere.example.test/"];
    }

    #[DataProvider('apiStatuses')]
    public function testPreservesHttpFailuresAndTheOptionalRetryAfterHeader(int $status, ?string $retryAfter): void
    {
        $headers = ['Content-Type' => 'text/html', 'Location' => 'https://elsewhere.example.test/'];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = $retryAfter;
        }
        $http = new ScriptedHttpClient(new Response($status, $headers, '<html>remote diagnostics</html>'));

        try {
            $this->executor($http)->getAt(self::PATH);
            self::fail('The API returned a failure.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame($retryAfter, $exception->getRetryAfter());
            self::assertNull($exception->getOperationOutcome());
            self::assertStringNotContainsString('remote diagnostics', (string) $exception);
            self::assertCount(1, $http->requests);
        }
    }

    /** @return iterable<string, array{int, ?string}> */
    public static function apiStatuses(): iterable
    {
        yield 'redirect' => [307, null];
        yield 'unauthorized' => [401, null];
        yield 'forbidden' => [403, null];
        yield 'not found' => [404, null];
        yield 'rate limited' => [429, '120'];
        yield 'date retry value' => [429, 'Wed, 18 Nov 2026 12:00:00 GMT'];
        yield 'rate limited without header' => [429, null];
        yield 'server failure' => [500, null];
    }

    public function testMakesTheStructuredOperationOutcomeAvailableOnlyThroughItsAccessor(): void
    {
        $body = file_get_contents(__DIR__ . '/../../Fixtures/operation-outcome.json');
        self::assertIsString($body);
        $http = new ScriptedHttpClient(new Response(404, ['Content-Type' => 'application/fhir+json'], $body));

        try {
            $this->executor($http)->getAt(self::PATH);
            self::fail('The API returned not found.');
        } catch (ApiException $exception) {
            self::assertSame(json_decode($body, true, 512, JSON_THROW_ON_ERROR), $exception->getOperationOutcome());
            self::assertStringNotContainsString('Synthetic product could not be found.', (string) $exception . var_export($exception, true));
        }
    }

    #[DataProvider('invalidOperationOutcomeEnvelopes')]
    public function testIgnoresAnInvalidOperationOutcomeEnvelope(string $body): void
    {
        $http = new ScriptedHttpClient(new Response(400, ['Content-Type' => 'application/json'], $body));

        try {
            $this->executor($http)->getAt(self::PATH);
            self::fail('The API returned bad request.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertNull($exception->getOperationOutcome());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOperationOutcomeEnvelopes(): iterable
    {
        yield 'empty issues' => ['{"resourceType":"OperationOutcome","issue":[]}'];
        yield 'object in place of issue list' => ['{"resourceType":"OperationOutcome","issue":{"0":{"severity":"error","code":"invalid"}}}'];
        yield 'missing code' => ['{"resourceType":"OperationOutcome","issue":[{"severity":"error"}]}'];
        yield 'severity outside the preview vocabulary' => ['{"resourceType":"OperationOutcome","issue":[{"severity":"success","code":"invalid"}]}'];
    }

    #[DataProvider('unexpectedResponses')]
    public function testRejectsInvalidSuccessResponses(int $status, ?string $contentType, string $body): void
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];
        $http = new ScriptedHttpClient(new Response($status, $headers, $body));

        $this->expectException(UnexpectedResponseException::class);
        $this->executor($http)->getAt(self::PATH);
    }

    /** @return iterable<string, array{int, ?string, string}> */
    public static function unexpectedResponses(): iterable
    {
        yield 'unexpected 2xx' => [201, 'application/json', '{}'];
        yield 'no content' => [204, null, ''];
        yield 'empty 200' => [200, 'application/json', ''];
        yield 'invalid JSON' => [200, 'application/json', '{"private":'];
        yield 'null' => [200, 'application/json', 'null'];
        yield 'scalar' => [200, 'application/json', '"abc"'];
        yield 'list' => [200, 'application/json', '[{}]'];
        yield 'empty list' => [200, 'application/json', '[]'];
        yield 'HTML' => [200, 'text/html', '{}'];
        yield 'XML' => [200, 'application/fhir+xml', '<Bundle/>'];
        yield 'missing media type' => [200, null, '{}'];
    }

    public function testFailureToReadTheErrorBodyStillPreservesTheStatus(): void
    {
        $http = new ScriptedHttpClient(new Response(503, ['Content-Type' => 'application/json'], new PumpStream(
            static fn() => throw new RuntimeException(self::TOKEN),
        )));

        try {
            $this->executor($http)->getAt(self::PATH);
            self::fail('The API returned unavailable.');
        } catch (ApiException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertNull($exception->getOperationOutcome());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, (string) $exception);
        }
    }

    public function testSanitizesTransportExceptionsAndPackageOwnedTraceArguments(): void
    {
        $setting = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($setting);

        try {
            $transport = new ScriptedHttpClient(
                static fn(RequestInterface $request) => throw new RequestException(self::TOKEN, $request),
            );
            try {
                $this->executor($transport)->getAt(self::PATH);
                self::fail('A transport error was expected.');
            } catch (TransportException $exception) {
                $diagnostics = (string) $exception . var_export($exception->getTrace(), true);
                self::assertNull($exception->getPrevious());
                self::assertStringNotContainsString(self::TOKEN, $diagnostics);
                self::assertStringNotContainsString('Authorization', $diagnostics);
                self::assertCount(1, $transport->requests);
            }

            $invalidJson = new ScriptedHttpClient(new Response(200, ['Content-Type' => 'application/json'], '{"token":"' . self::TOKEN));
            try {
                $this->executor($invalidJson)->getAt(self::PATH);
                self::fail('Invalid JSON was accepted.');
            } catch (UnexpectedResponseException $exception) {
                self::assertNull($exception->getPrevious());
                self::assertStringNotContainsString(self::TOKEN, (string) $exception . var_export($exception->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', $setting);
        }
    }

    public function testLeavesProgrammingErrorsFromTransportVisible(): void
    {
        $http = new ScriptedHttpClient(static fn() => throw new LogicException('synthetic programming error'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('synthetic programming error');
        $this->executor($http)->getAt(self::PATH);
    }

    public function testLeavesProgrammingErrorsFromTheRequestFactoryVisible(): void
    {
        $error = new RuntimeException('synthetic request factory error');
        $factory = $this->createStub(RequestFactoryInterface::class);
        $factory->method('createRequest')->willThrowException($error);
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');
        $executor = new ApiRequestExecutor(new ScriptedHttpClient(), $factory, $tokens);

        $this->expectExceptionObject($error);
        $executor->getAt(self::PATH);
    }

    private function executor(ScriptedHttpClient $http, ?string $baseUri = null): ApiRequestExecutor
    {
        $tokens = $this->createStub(AccessTokenProviderInterface::class);
        $tokens->method('getAccessToken')->willReturn(self::TOKEN);
        $factory = new HttpFactory();

        return $baseUri === null
            ? new ApiRequestExecutor($http, $factory, $tokens)
            : new ApiRequestExecutor($http, $factory, $tokens, $baseUri);
    }
}
