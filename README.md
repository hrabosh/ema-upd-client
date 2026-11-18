# EMA UPD Client

An unofficial, framework-independent PHP client for the European Medicines Agency's Union Product Database (UPD) for veterinary medicines.

Requires PHP 8.3 or later. The client supports OAuth2 Client Credentials, product search, lookup by resource ID, `$everything`, and explicit pagination. It targets the veterinary UPD public v4 API, OpenAPI document `1.7.2631-0`, and FHIR 4.4.0 (R5 Preview #2). Document retrieval is outside the scope of `0.1.0`. [API contract and production observations](docs/api-contract.md)

The development setup and planned scope are available on GitHub. [Contributing guide](https://github.com/hrabosh/ema-upd-client/blob/main/CONTRIBUTING.md) · [Release tracker](https://github.com/hrabosh/ema-upd-client/issues/1)

The MIT license covers this project's original code. EMA provides the upstream data. Public API access is subject to the terms presented during EMA registration and the UPD Access Policy. [EMA UPD overview](https://www.ema.europa.eu/en/veterinary-regulatory-overview/veterinary-medicinal-products-regulation/union-product-database) · [EMA legal notice](https://www.ema.europa.eu/en/about-us/about-website/legal-notice) · [UPD Access Policy](https://www.ema.europa.eu/en/documents/other/union-product-database-upd-access-policy-veterinary-medicinal-products_en.pdf)

## Installation

Publication is pending. To try the release candidate from a local checkout, run these commands in a separate consumer directory. The examples use Guzzle 7 and Guzzle PSR-7 2. Other PSR-18 clients and PSR-17 factories can be injected.

```sh
composer init --name=example/ema-upd-demo --type=project --no-interaction
composer config repositories.ema-upd-client vcs /absolute/path/to/ema-upd-client
composer require 'hrabosh/ema-upd-client:dev-chore/upd-release-preparation' 'guzzlehttp/guzzle:^7' 'guzzlehttp/psr7:^2'
```

After the maintainer publishes the `0.1.0` Git tag, a consumer can use `https://github.com/hrabosh/ema-upd-client.git` as the VCS URL and `hrabosh/ema-upd-client:0.1.0` as the package constraint. [Composer VCS repositories](https://getcomposer.org/doc/05-repositories.md#vcs)

## Get API credentials

Create an EMA account in the production EMA Account Management portal. Under **Manage Access**, select **Request Public API Access**, accept the API terms and submit a technical contact address. The UPD registration confirmation provides your client ID, client secret, OAuth2 token endpoint and scope. [EMA Account Management](https://register.ema.europa.eu/) · [UPD registration guide, section 5.1.3](https://www.ema.europa.eu/en/documents/other/upd-registration-guide-ui-api-users_en.pdf#page=17)

Set `EMA_UPD_CLIENT_ID`, `EMA_UPD_CLIENT_SECRET`, `EMA_UPD_TOKEN_ENDPOINT` and `EMA_UPD_SCOPE` in the local environment from that confirmation. Set `EMA_UPD_PRODUCT_NAME` to a real query, such as `Nobivac Rabies`. The API base defaults to production. `EMA_UPD_BASE_URI` optionally selects another HTTPS API base associated with your credentials. The source checkout includes a placeholder `.env.example`. Export the values in your shell or process configuration before running the example.

```sh
php vendor/hrabosh/ema-upd-client/examples/quickstart.php
```

From the source checkout, run `composer install` and then `php examples/quickstart.php`. The script performs one search, one product lookup and one `$everything` lookup. It requires configuration before making any HTTP request.

## Authentication

The bundled examples use the following constructor setup with values supplied by the application. An optional PSR-20 clock controls expiry checks. Applications that manage tokens centrally can implement `AccessTokenProviderInterface` directly.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hrabosh\EmaUpd\Auth\ClientCredentials;
use Hrabosh\EmaUpd\Auth\ClientCredentialsTokenProvider;
use Hrabosh\EmaUpd\UpdClient;

$http = new Client([
    'connect_timeout' => 5.0,
    'timeout' => 30.0,
    'verify' => true,
    'allow_redirects' => false,
]);
$factory = new HttpFactory();
$credentials = new ClientCredentials($clientId, $clientSecret, $tokenEndpoint, $scope);
$tokens = new ClientCredentialsTokenProvider($credentials, $http, $factory, $factory);
$client = new UpdClient($http, $factory, $tokens);
```

The provider reuses a valid token in memory for its own instance. It calculates usable expiry from the start of the token request using `expires_in`, minus 10% rounded down and capped at 30 seconds. A response without `expires_in` provides a token for the current call only. The provider requests another token on a later call when the cached token expires. Authentication and transport errors are exposed as `AuthenticationException` and `TransportException`.

The transport owns timeouts, TLS and redirect handling. Guzzle's PSR-18 `sendRequest()` returns redirects directly. The separate connection timeout uses Guzzle's cURL handler. The client makes one attempt per request. Configure transport and application logs to omit credential-bearing request bodies, authorization headers and token responses. [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html#allow-redirects)

## Product lookup

Pass the resource's `id` as a string, including any leading zeros. Resource IDs are the values accepted by the lookup methods. Permanent identifiers and other identifiers belong to their respective search filters.

```php
$product = $client->getMedicinalProduct($productId);
$related = $client->getMedicinalProductEverything($productId);
```

`$product->getId()` returns `null` when the response omits an ID. Bundle entries can contain several resource types. `getEntries()` returns an empty list when entries are absent, and `getTotal()` and `getNextLink()` return `null` when those fields are absent. Both views expose the complete decoded FHIR payload through `toArray()`.

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

`nextPage()` follows one validated server link and returns `null` when the Bundle has no next link. When EMA echoes literal spaces in `productName`, the client encodes those spaces as `%20` and preserves the continuation token. If the request factory changes the prepared query, the client raises `UnexpectedResponseException` before asking for a token. Page requests stay within the configured HTTPS origin and API path. The same method accepts continuation links from `$everything` Bundles. [Production pagination behavior](docs/api-contract.md#local-observations)

## API errors

UPD HTTP failures, including redirects, expose their status via `ApiException::getStatusCode()`. Use `getRetryAfter()` to inspect a supplied `Retry-After` header and `getOperationOutcome()` to inspect a structured FHIR error when available. Use those values to decide how to handle rate limits and server errors. Each request makes one attempt.

`UnexpectedResponseException` identifies an unusable successful response. UPD operations expect HTTP 200 with a JSON object and an `application/fhir+json` or `application/json` content type. A PSR-18 sending failure raises `TransportException`.

## Live smoke check

To run the release check locally, set the same credentials and name query as for the quickstart. Also set `EMA_UPD_PERMANENT_IDENTIFIER` to a known permanent identifier for the first product returned by that name query. Choose a name that yields multiple pages at `_count=1` and selects a different first product from the unfiltered search. The smoke script checks the name and identifier results, product lookup, `$everything`, and one server-supplied next link. It prints HTTP statuses, media types and resource summaries.

```sh
php vendor/hrabosh/ema-upd-client/examples/smoke.php
```

From the source checkout, run `php examples/smoke.php`. A missing next link leaves pagination unverified and causes a nonzero exit. Ordinary tests and CI run offline. [Release checklist](docs/release.md) · [Private security reporting](SECURITY.md)
