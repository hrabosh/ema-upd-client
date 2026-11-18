# EMA UPD Client

An unofficial, framework-independent PHP client for the European Medicines Agency's Union Product Database (UPD) for veterinary medicines.

The first release is in development and targets PHP 8.3 and later. The client supports OAuth2 Client Credentials authentication, product search, lookup by ID, and retrieval of related resources with `$everything`.

The development setup and planned scope are available on GitHub. [Contributing guide](https://github.com/hrabosh/ema-upd-client/blob/main/CONTRIBUTING.md) · [Release tracker](https://github.com/hrabosh/ema-upd-client/issues/1)

The MIT license covers this project's original code. EMA data and documents are subject to their own terms.

## Authentication

Pass the four values from EMA registration to `ClientCredentials` and inject a PSR-18 HTTP client plus PSR-17 request and stream factories into `ClientCredentialsTokenProvider`. An optional PSR-20 clock controls expiry checks. Applications that manage tokens centrally can implement `AccessTokenProviderInterface` directly.

The provider reuses a valid token in memory for its own instance. It calculates usable expiry from the start of the token request using `expires_in`, minus 10% rounded down and capped at 30 seconds. A response without `expires_in` provides a token for the current call only. The provider requests another token on a later call when the cached token expires. Authentication and transport errors are exposed as `AuthenticationException` and `TransportException`.

Configure finite timeouts, TLS verification, and redirect handling on the injected transport. Use a PSR-18 `sendRequest()` implementation that returns redirects directly. The provider rejects redirect responses and makes one HTTP attempt per acquisition. Configure any application or transport logging to omit credential-bearing request bodies, authorization headers, and token responses.

## Product lookup

Pass the product resource ID as a string, including any leading zeros. The following calls share a configured HTTP client, request factory and token provider.

```php
use Hrabosh\EmaUpd\UpdClient;

$client = new UpdClient($httpClient, $requestFactory, $tokenProvider);
$product = $client->getMedicinalProduct($productId);
$related = $client->getMedicinalProductEverything($productId);
```

`$product->getId()` returns the response ID when present. `$related->getEntries()` exposes complete Bundle entries, which can contain different resource types. Each call fetches one response. Use `$related->getNextLink()` to inspect any continuation link. Both response views provide the complete decoded FHIR payload through `toArray()`.

## Product search and pagination

Pass an associative array of filter names and string values. An empty array searches the collection without filters. Use the exact UPD parameter names, including `permanantIdentifier` as spelled in the public OpenAPI. [Supported search parameters](docs/api-contract.md#search-parameters)

Choose how many pages to fetch. This example fetches up to three pages, including the initial search.

```php
$maxPages = 3;
$page = $client->searchMedicinalProducts(['productName' => 'Nobivac Rabies', '_count' => '1']);
$entries = [];

for ($pageNumber = 1; $page !== null; ++$pageNumber) {
    foreach ($page->getEntries() as $entry) {
        $entries[] = $entry;
    }

    if ($pageNumber >= $maxPages) {
        break;
    }

    $page = $client->nextPage($page);
}
```

`nextPage()` follows one validated server link and returns `null` when the Bundle has no next link. The link supplies its own query. If the request factory changes that query, the client raises `UnexpectedResponseException` before asking for a token. The client confines page requests to the configured HTTPS origin and API path. The same method also works with continuation links from `$everything` Bundles.

## API errors

UPD HTTP failures, including redirects, expose their status via `ApiException::getStatusCode()`. Use `getRetryAfter()` to inspect a supplied `Retry-After` header and `getOperationOutcome()` to inspect a structured FHIR error when available. Use those values to decide how to handle rate limits and server errors. Each request makes one attempt.

`UnexpectedResponseException` identifies an unusable successful response. UPD operations expect HTTP 200 with a JSON object and an `application/fhir+json` or `application/json` content type. A PSR-18 sending failure raises `TransportException`.
