<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hrabosh\EmaUpd\Auth\AccessTokenProviderInterface;
use Hrabosh\EmaUpd\Exception\ApiException;
use Hrabosh\EmaUpd\Exception\UnexpectedResponseException;
use Hrabosh\EmaUpd\Tests\Support\ScriptedHttpClient;
use Hrabosh\EmaUpd\UpdClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use ReflectionMethod;

final class UpdClientSearchTest extends TestCase
{
    private const string BASE = 'https://spor.azure-api.net/upd/public/v4/';
    private const string SEARCH = self::BASE . 'MedicinalProductDefinition';

    public function testEmptySearchSendsOneGetAndMissingNextLinkNeedsNoToken(): void
    {
        $http = new ScriptedHttpClient(self::fixture('empty-search'));
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->once())->method('getAccessToken')->willReturn('offline-token');
        $client = new UpdClient($http, new HttpFactory(), $tokens);

        $page = $client->searchMedicinalProducts();
        self::assertSame([], $page->getEntries());
        self::assertSame(0, $page->getTotal());
        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame(self::SEARCH, (string) $http->requests[0]->getUri());
        self::assertSame('Bearer offline-token', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertNull($client->nextPage($page));
        self::assertCount(1, $http->requests);
    }

    public function testEncodesAllDocumentedSearchParametersAsStrings(): void
    {
        $http = new ScriptedHttpClient(self::fixture('product-search'));

        $page = $this->client($http)->searchMedicinalProducts(parameters: [
            'productName' => 'Vet + č & care',
            'permanantIdentifier' => '00042',
            'productIdentifier' => '001',
            'productOwner' => 'Owner',
            'authorizationNumber' => 'a=b',
            'atcVetCode' => 'Q',
            'authorizationStatus' => 'status',
            'procedureType' => 'type',
            'authorizationCountry' => 'country',
            'targetSpecies' => 'species',
            '_ct' => 'A+/%=',
            '_count' => '02',
            '_summary' => 'true',
            '_lastUpdated' => 'gt2025-10-10',
        ]);

        self::assertSame(1, $page->getTotal());
        self::assertCount(1, $http->requests);
        self::assertSame(self::SEARCH . '?' . implode('&', [
            'productName=Vet%20%2B%20%C4%8D%20%26%20care',
            'permanantIdentifier=00042',
            'productIdentifier=001',
            'productOwner=Owner',
            'authorizationNumber=a%3Db',
            'atcVetCode=Q',
            'authorizationStatus=status',
            'procedureType=type',
            'authorizationCountry=country',
            'targetSpecies=species',
            '_ct=A%2B%2F%25%3D',
            '_count=02',
            '_summary=true',
            '_lastUpdated=gt2025-10-10',
        ]), (string) $http->requests[0]->getUri());
    }

    /** @param array<int|string, mixed> $parameters */
    #[DataProvider('invalidParameters')]
    public function testRejectsInvalidSearchParametersBeforeAcquiringAToken(array $parameters): void
    {
        $http = new ScriptedHttpClient();
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');
        $client = new UpdClient($http, new HttpFactory(), $tokens);
        $search = new ReflectionMethod(UpdClient::class, 'searchMedicinalProducts');

        $this->expectException(InvalidArgumentException::class);
        try {
            $search->invoke($client, $parameters);
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }

    /** @return iterable<string, array{array<int|string, mixed>}> */
    public static function invalidParameters(): iterable
    {
        yield 'unknown key' => [['page' => '1']];
        yield 'different spelling' => [['permanentIdentifier' => '1']];
        yield 'integer key' => [[0 => '1']];
        yield 'integer value' => [['_count' => 1]];
        yield 'list value' => [['productName' => ['one', 'two']]];
        yield 'boolean value' => [['_summary' => true]];
        yield 'null value' => [['productName' => null]];
    }

    #[DataProvider('wrongSearchEnvelopes')]
    public function testSearchRequiresASearchsetBundle(string $body): void
    {
        $http = new ScriptedHttpClient(self::response($body));

        $this->expectException(UnexpectedResponseException::class);
        $this->client($http)->searchMedicinalProducts();
    }

    /** @return iterable<string, array{string}> */
    public static function wrongSearchEnvelopes(): iterable
    {
        yield 'wrong resource' => ['{"resourceType":"MedicinalProductDefinition","type":"searchset"}'];
        yield 'missing type' => ['{"resourceType":"Bundle"}'];
        yield 'wrong type' => ['{"resourceType":"Bundle","type":"collection"}'];
        yield 'malformed type' => ['{"resourceType":"Bundle","type":["searchset"]}'];
    }

    public function testFollowsOneQueryOnlyLinkAndUsesItInsteadOfTheOriginalFilters(): void
    {
        $http = new ScriptedHttpClient(self::fixture('next-link'), self::page());
        $client = $this->client($http);

        $first = $client->searchMedicinalProducts(['productName' => 'Initial filter', '_count' => '2']);
        self::assertCount(1, $http->requests);
        $second = $client->nextPage(page: $first);

        self::assertNotNull($second);
        self::assertSame([], $second->getEntries());
        self::assertNull($second->getTotal());
        self::assertSame(self::SEARCH . '?productName=Initial%20filter&_count=2', (string) $http->requests[0]->getUri());
        self::assertSame(self::SEARCH . '?productName=Synthetic&_count=1&_ct=synthetic%2Bpage%2F2%3D', (string) $http->requests[1]->getUri());
        self::assertSame((string) $http->requests[1]->getUri(), (string) $second->getRequestUri());
        self::assertNull($client->nextPage($second));
        self::assertCount(2, $http->requests);
    }

    public function testEncodesSpacesInTheProductNameEchoedByEmaWithoutRewritingTheToken(): void
    {
        $http = new ScriptedHttpClient(self::fixture('next-link-unescaped-product-name'), self::page());
        $client = $this->client($http);
        $page = $client->searchMedicinalProducts(['productName' => 'Synthetic medicine', '_count' => '1']);

        self::assertNotNull($client->nextPage($page));
        self::assertSame(self::SEARCH . '?productName=Synthetic%20medicine&_count=1&_ct=synthetic%2b%2F%3D', (string) $http->requests[1]->getUri());
        self::assertCount(2, $http->requests);
    }

    #[DataProvider('sameOriginLinks')]
    public function testAcceptsSameOriginLinksAndPreservesTheSuppliedQuery(string $link): void
    {
        $query = '?_ct=a%2b%2F%3D&productName=New+Filter&part=1&part=2';
        $http = new ScriptedHttpClient(self::page($link . $query), self::page());
        $client = $this->client($http);

        $first = $client->searchMedicinalProducts();
        self::assertNotNull($client->nextPage($first));
        self::assertSame(self::SEARCH . $query, (string) $http->requests[1]->getUri());
        self::assertSame('Bearer offline-token', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertCount(2, $http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function sameOriginLinks(): iterable
    {
        yield 'absolute' => ['https://SPOR.AZURE-API.NET:443/upd/public/v4/MedicinalProductDefinition'];
        yield 'network path' => ['//spor.azure-api.net/upd/public/v4/MedicinalProductDefinition'];
        yield 'network path with current directory' => ['//spor.azure-api.net/upd/public/v4/./MedicinalProductDefinition'];
        yield 'root relative' => ['/upd/public/v4/MedicinalProductDefinition'];
        yield 'directory relative' => ['MedicinalProductDefinition'];
        yield 'current directory' => ['./MedicinalProductDefinition'];
    }

    public function testResolvesEveryLinkAgainstThePageThatProducedIt(): void
    {
        $http = new ScriptedHttpClient(self::page('nested/page?_ct=first'), self::page('?_ct=second'), self::page());
        $client = $this->client($http);

        $first = $client->searchMedicinalProducts();
        $second = $client->nextPage($first);
        self::assertNotNull($second);
        self::assertCount(2, $http->requests);
        self::assertSame(self::BASE . 'nested/page?_ct=first', (string) $second->getRequestUri());

        $third = $client->nextPage($second);
        self::assertNotNull($third);
        self::assertSame(self::BASE . 'nested/page?_ct=second', (string) $http->requests[2]->getUri());
        self::assertCount(3, $http->requests);
    }

    public function testCanFollowAContinuationForAnEverythingBundle(): void
    {
        $http = new ScriptedHttpClient(self::page('?_ct=more'), self::page(null, 'collection'));
        $client = $this->client($http, 'https://uat.example.test:8443/upd/public/v4');

        $first = $client->getMedicinalProductEverything('000123');
        $second = $client->nextPage($first);
        self::assertNotNull($second);
        self::assertSame('collection', $second->toArray()['type']);
        self::assertSame('https://uat.example.test:8443/upd/public/v4/MedicinalProductDefinition/000123/$everything?_ct=more', (string) $http->requests[1]->getUri());
        self::assertCount(2, $http->requests);
    }

    public function testValidatesTheSearchsetTypeForLaterSearchPages(): void
    {
        $http = new ScriptedHttpClient(self::page('?_ct=more'), self::page('?_ct=last'), self::page(null, 'collection'));
        $client = $this->client($http);
        $first = $client->searchMedicinalProducts();
        $second = $client->nextPage($first);
        self::assertNotNull($second);

        $this->expectException(UnexpectedResponseException::class);
        try {
            $client->nextPage($second);
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    public function testRejectsARequestFactoryThatChangesTheContinuationQuery(): void
    {
        $page = $this->client(new ScriptedHttpClient(self::page('?_ct=A%2b')))->searchMedicinalProducts();
        $http = new ScriptedHttpClient();
        $factory = $this->createStub(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturn(new Request('GET', self::SEARCH . '?_ct=A%2B'));
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');
        $client = new UpdClient($http, $factory, $tokens);

        $this->expectException(UnexpectedResponseException::class);
        try {
            $client->nextPage($page);
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }

    #[DataProvider('unsafeLinks')]
    public function testRejectsAnUnsafeContinuationBeforeAcquiringATokenForIt(string $link): void
    {
        $http = new ScriptedHttpClient(self::page($link));
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->once())->method('getAccessToken')->willReturn('offline-token');
        $client = new UpdClient($http, new HttpFactory(), $tokens);
        $first = $client->searchMedicinalProducts();

        $this->expectException(UnexpectedResponseException::class);
        try {
            $client->nextPage($first);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeLinks(): iterable
    {
        yield 'different host' => ['https://other.example.test/upd/public/v4/MedicinalProductDefinition'];
        yield 'network path to different host' => ['//other.example.test/upd/public/v4/MedicinalProductDefinition'];
        yield 'deceptive suffix' => ['https://spor.azure-api.net.other.example.test/upd/public/v4/MedicinalProductDefinition'];
        yield 'wrong port' => ['https://spor.azure-api.net:8443/upd/public/v4/MedicinalProductDefinition'];
        yield 'HTTP downgrade' => ['http://spor.azure-api.net/upd/public/v4/MedicinalProductDefinition'];
        yield 'userinfo' => ['https://user@spor.azure-api.net/upd/public/v4/MedicinalProductDefinition'];
        yield 'empty userinfo' => ['//@spor.azure-api.net/upd/public/v4/MedicinalProductDefinition'];
        yield 'fragment' => ['?_ct=token#section'];
        yield 'empty fragment' => ['?_ct=token#'];
        yield 'path sibling with shared prefix' => ['/upd/public/v40/MedicinalProductDefinition?_ct=token'];
        yield 'parent segment' => ['../v4/MedicinalProductDefinition?_ct=token'];
        yield 'absolute traversal' => [self::BASE . '../v4/MedicinalProductDefinition'];
        yield 'encoded current segment' => ['%2e/MedicinalProductDefinition'];
        yield 'nested encoded parent segment' => ['%25%32%65%25%32%65/MedicinalProductDefinition'];
        yield 'encoded path separator' => ['x%2Fy?_ct=token'];
        yield 'backslash' => ['x\\y?_ct=token'];
        yield 'malformed percent escape' => ['?_ct=bad%zz'];
        yield 'raw whitespace' => ['?_ct=bad token'];
        yield 'product name with raw line break' => ["?productName=bad\nname&_ct=token"];
        yield 'raw invalid query character' => ['?_ct=token[123]'];
        yield 'opaque absolute URI' => ['https:MedicinalProductDefinition?_ct=token'];
        yield 'authority without the required delimiter' => ['spor.azure-api.net:443/upd/public/v4/MedicinalProductDefinition'];
        yield 'explicit empty query dropped by the request factory' => ['?'];
    }

    public function testCannotUseAPageFromADifferentApiBase(): void
    {
        $otherClient = $this->client(
            new ScriptedHttpClient(self::page(self::SEARCH . '?_ct=server-link')),
            'https://spor.azure-api.net/upd/other/v4/',
        );
        $foreignPage = $otherClient->searchMedicinalProducts();
        $http = new ScriptedHttpClient();
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');
        $client = new UpdClient($http, new HttpFactory(), $tokens);

        $this->expectException(UnexpectedResponseException::class);
        try {
            $client->nextPage($foreignPage);
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }

    #[DataProvider('failedPageStatuses')]
    public function testPreservesNextPageHttpFailuresWithoutRetryingOrRedirecting(int $status): void
    {
        $http = new ScriptedHttpClient(self::page('?_ct=more'), new Response(
            $status,
            ['Location' => 'https://other.example.test/', 'Retry-After' => '120'],
        ));
        $client = $this->client($http);
        $first = $client->searchMedicinalProducts();

        try {
            $client->nextPage($first);
            self::fail('The next page should have failed.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame('120', $exception->getRetryAfter());
            self::assertCount(2, $http->requests);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function failedPageStatuses(): iterable
    {
        yield 'rate limit' => [429];
        yield 'redirect' => [307];
    }

    private function client(ScriptedHttpClient $http, string $baseUri = self::BASE): UpdClient
    {
        $tokens = $this->createStub(AccessTokenProviderInterface::class);
        $tokens->method('getAccessToken')->willReturn('offline-token');

        return new UpdClient($http, new HttpFactory(), $tokens, $baseUri);
    }

    private static function fixture(string $name): Response
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.json');
        self::assertIsString($body);

        return self::response($body);
    }

    private static function page(?string $next = null, string $type = 'searchset'): Response
    {
        $payload = ['resourceType' => 'Bundle', 'type' => $type];
        if ($next !== null) {
            $payload['link'] = [['relation' => 'next', 'url' => $next]];
        }

        return self::response(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function response(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/fhir+json'], $body);
    }
}
