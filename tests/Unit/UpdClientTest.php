<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hrabosh\EmaUpd\Auth\AccessTokenProviderInterface;
use Hrabosh\EmaUpd\Exception\ApiException;
use Hrabosh\EmaUpd\Exception\UnexpectedResponseException;
use Hrabosh\EmaUpd\Tests\Support\ScriptedHttpClient;
use Hrabosh\EmaUpd\UpdClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdClientTest extends TestCase
{
    private const string BASE = 'https://spor.azure-api.net/upd/public/v4/';

    public function testLooksUpAProductAndItsRelatedResourcesUsingTheSameClient(): void
    {
        $productFixture = self::fixture('product');
        $bundleFixture = self::fixture('product-everything');
        $http = new ScriptedHttpClient(self::response($productFixture), self::response($bundleFixture));
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->exactly(2))->method('getAccessToken')->willReturn('offline-token');
        $client = new UpdClient(httpClient: $http, requestFactory: new HttpFactory(), tokenProvider: $tokens);

        self::assertSame(0, $http->requestCount());

        $product = $client->getMedicinalProduct(id: '000123');
        self::assertSame(1, $http->requestCount());
        self::assertSame('000123', $product->getId());
        self::assertSame(json_decode($productFixture, true, 512, JSON_THROW_ON_ERROR), $product->toArray());
        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame(self::BASE . 'MedicinalProductDefinition/000123', (string) $http->requests[0]->getUri());
        self::assertSame('Bearer offline-token', $http->requests[0]->getHeaderLine('Authorization'));

        $bundle = $client->getMedicinalProductEverything(id: '000123');
        self::assertSame(2, $http->requestCount());
        self::assertSame('GET', $http->requests[1]->getMethod());
        self::assertSame(self::BASE . 'MedicinalProductDefinition/000123/$everything', (string) $http->requests[1]->getUri());
        self::assertSame('Bearer offline-token', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame(json_decode($bundleFixture, true, 512, JSON_THROW_ON_ERROR), $bundle->toArray());
        self::assertSame([
            ['resource' => ['resourceType' => 'MedicinalProductDefinition', 'id' => '000123']],
            ['resource' => [
                'resourceType' => 'AdministrableProductDefinition',
                'id' => 'synthetic-form',
                'formOf' => [['reference' => 'MedicinalProductDefinition/000123']],
            ]],
        ], $bundle->getEntries());
        self::assertNull($bundle->getTotal());
        self::assertNull($bundle->getNextLink());
    }

    public function testReturnsNullForAnAbsentProductId(): void
    {
        $http = new ScriptedHttpClient(self::response('{"resourceType":"MedicinalProductDefinition"}'));
        $product = $this->client($http)->getMedicinalProduct('some-product');

        self::assertNull($product->getId());
        self::assertSame(['resourceType' => 'MedicinalProductDefinition'], $product->toArray());
    }

    public function testDistinguishesAQuotedNumericIdAndPreservesAnOversizedUnknownNumber(): void
    {
        $http = new ScriptedHttpClient(self::response(
            '{"resourceType":"MedicinalProductDefinition","id":"9223372036854775808","unknown":{"value":9223372036854775808}}',
        ));
        $product = $this->client($http)->getMedicinalProduct('9223372036854775808');

        self::assertSame('9223372036854775808', $product->getId());
        self::assertSame(['value' => '9223372036854775808'], $product->toArray()['unknown']);
    }

    public function testAllowsAnEmptyEverythingBundleAndKeepsAnExplicitZeroTotal(): void
    {
        $http = new ScriptedHttpClient(
            self::response('{"resourceType":"Bundle"}'),
            self::response(self::fixture('empty-search')),
            self::response('{"resourceType":"Bundle","type":"collection","link":[]}'),
        );
        $client = $this->client($http);

        $omitted = $client->getMedicinalProductEverything('000123');
        self::assertSame([], $omitted->getEntries());
        self::assertNull($omitted->getTotal());
        self::assertNull($omitted->getNextLink());
        self::assertSame(['resourceType' => 'Bundle'], $omitted->toArray());

        $explicit = $client->getMedicinalProductEverything('000123');
        self::assertSame([], $explicit->getEntries());
        self::assertSame(0, $explicit->getTotal());
        self::assertNull($explicit->getNextLink());

        $collection = $client->getMedicinalProductEverything('000123');
        self::assertSame([], $collection->getEntries());
        self::assertNull($collection->getNextLink());
        self::assertSame(['resourceType' => 'Bundle', 'type' => 'collection', 'link' => []], $collection->toArray());
        self::assertCount(3, $http->requests);
    }

    public function testExposesANextLinkWithoutFetchingAndRetainsItsRequestUri(): void
    {
        $http = new ScriptedHttpClient(self::response(self::fixture('next-link')));
        $client = $this->client($http, 'https://uat.example.test:8443/upd/public/v4');
        $bundle = $client->getMedicinalProductEverything('000123');

        self::assertSame('?productName=Synthetic&_count=1&_ct=synthetic%2Bpage%2F2%3D', $bundle->getNextLink());
        self::assertSame('https://uat.example.test:8443/upd/public/v4/MedicinalProductDefinition/000123/$everything', (string) $bundle->getRequestUri());
        self::assertSame(2, $bundle->getTotal());
        self::assertCount(1, $http->requests);
    }

    public function testReturnedArraysCanBeEditedWithoutChangingEitherView(): void
    {
        $productFixture = self::fixture('product');
        $bundleFixture = self::fixture('product-search');
        $http = new ScriptedHttpClient(self::response($productFixture), self::response($bundleFixture));
        $client = $this->client($http);
        $product = $client->getMedicinalProduct('000123');
        $bundle = $client->getMedicinalProductEverything('000123');
        $originalProduct = $product->toArray();
        $originalBundle = $bundle->toArray();
        $originalEntries = $bundle->getEntries();
        $expectedBundle = json_decode($bundleFixture, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expectedBundle);
        self::assertSame($expectedBundle['entry'], $originalEntries);

        $productCopy = $product->toArray();
        self::assertIsArray($productCopy['extension']);
        self::assertIsArray($productCopy['extension'][0]);
        $productCopy['extension'][0]['valueString'] = 'edited';
        self::assertSame('edited', $productCopy['extension'][0]['valueString']);
        self::assertSame($originalProduct, $product->toArray());

        $entriesCopy = $bundle->getEntries();
        self::assertIsArray($entriesCopy[0]['resource']);
        $entriesCopy[0]['resource']['id'] = 'edited';
        self::assertSame('edited', $entriesCopy[0]['resource']['id']);
        $bundleCopy = $bundle->toArray();
        $bundleCopy['total'] = 99;
        self::assertSame(99, $bundleCopy['total']);
        self::assertSame($originalEntries, $bundle->getEntries());
        self::assertSame($originalBundle, $bundle->toArray());
        self::assertSame($expectedBundle, $originalBundle);
    }

    #[DataProvider('unsafeIds')]
    public function testRejectsUnsafeIdsBeforeRequestingAToken(string $id): void
    {
        $http = new ScriptedHttpClient();
        $tokens = $this->createMock(AccessTokenProviderInterface::class);
        $tokens->expects($this->never())->method('getAccessToken');
        $client = new UpdClient($http, new HttpFactory(), $tokens);

        foreach ([$client->getMedicinalProduct(...), $client->getMedicinalProductEverything(...)] as $operation) {
            try {
                $operation($id);
                self::fail('The product ID was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $http->requestCount());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeIds(): iterable
    {
        yield 'empty' => [''];
        yield 'slash' => ['different/product'];
        yield 'parent path' => ['..'];
        yield 'escaped slash' => ['a%2Fb'];
    }

    #[DataProvider('invalidProducts')]
    public function testRejectsMalformedProductResponses(string $body): void
    {
        $http = new ScriptedHttpClient(self::response($body));

        $this->expectException(UnexpectedResponseException::class);
        $this->client($http)->getMedicinalProduct('000123');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProducts(): iterable
    {
        yield 'wrong resource' => ['{"resourceType":"Bundle"}'];
        yield 'missing resource type' => ['{"id":"000123"}'];
        yield 'numeric ID' => ['{"resourceType":"MedicinalProductDefinition","id":123}'];
        yield 'oversized numeric ID' => ['{"resourceType":"MedicinalProductDefinition","id":9223372036854775808}'];
        yield 'null ID' => ['{"resourceType":"MedicinalProductDefinition","id":null}'];
        yield 'malformed JSON' => ['{"resourceType":"MedicinalProductDefinition"'];
    }

    #[DataProvider('invalidBundles')]
    public function testRejectsMalformedBundleResponses(string $body): void
    {
        $http = new ScriptedHttpClient(self::response($body));

        $this->expectException(UnexpectedResponseException::class);
        $this->client($http)->getMedicinalProductEverything('000123');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBundles(): iterable
    {
        yield 'wrong resource' => ['{"resourceType":"MedicinalProductDefinition"}'];
        yield 'null entry' => ['{"resourceType":"Bundle","entry":null}'];
        yield 'empty entry object' => ['{"resourceType":"Bundle","entry":{}}'];
        yield 'entry object instead of a list' => ['{"resourceType":"Bundle","entry":{"0":{"resource":{"id":"000123"}}}}'];
        yield 'list in place of an entry object' => ['{"resourceType":"Bundle","entry":[[]]}'];
        yield 'null total' => ['{"resourceType":"Bundle","total":null}'];
        yield 'negative total' => ['{"resourceType":"Bundle","total":-1}'];
        yield 'string total' => ['{"resourceType":"Bundle","total":"1"}'];
        yield 'fractional total' => ['{"resourceType":"Bundle","total":1.5}'];
        yield 'null link' => ['{"resourceType":"Bundle","link":null}'];
        yield 'empty link object' => ['{"resourceType":"Bundle","link":{}}'];
        yield 'link object instead of a list' => ['{"resourceType":"Bundle","link":{"0":{"relation":"next","url":"?_ct=2"}}}'];
        yield 'non-object link entry' => ['{"resourceType":"Bundle","link":[42]}'];
        yield 'non-string relation' => ['{"resourceType":"Bundle","link":[{"relation":42,"url":"?_ct=2"}]}'];
        yield 'oversized numeric relation' => ['{"resourceType":"Bundle","link":[{"relation":9223372036854775808,"url":"?_ct=2"}]}'];
        yield 'absent URL' => ['{"resourceType":"Bundle","link":[{"relation":"next"}]}'];
        yield 'non-string URL' => ['{"resourceType":"Bundle","link":[{"relation":"next","url":42}]}'];
        yield 'oversized numeric URL' => ['{"resourceType":"Bundle","link":[{"relation":"next","url":9223372036854775808}]}'];
        yield 'conflicting next links' => ['{"resourceType":"Bundle","link":[{"relation":"next","url":"?_ct=2"},{"relation":"next","url":"?_ct=3"}]}'];
    }

    public function testPreservesA404AsAnApiError(): void
    {
        $http = new ScriptedHttpClient(self::response(self::fixture('operation-outcome'), 404));

        try {
            $this->client($http)->getMedicinalProduct('absent');
            self::fail('The API returned HTTP 404.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame('OperationOutcome', $exception->getOperationOutcome()['resourceType'] ?? null);
            self::assertCount(1, $http->requests);
        }
    }

    private function client(ScriptedHttpClient $http, string $baseUri = self::BASE): UpdClient
    {
        $tokens = $this->createStub(AccessTokenProviderInterface::class);
        $tokens->method('getAccessToken')->willReturn('offline-token');

        return new UpdClient($http, new HttpFactory(), $tokens, $baseUri);
    }

    private static function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.json');
        self::assertIsString($body);

        return $body;
    }

    private static function response(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/fhir+json'], $body);
    }
}
