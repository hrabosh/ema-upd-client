# EMA UPD Client

An unofficial, framework-independent PHP client for the European Medicines Agency's Union Product Database (UPD) for veterinary medicines.

The first release is in development and targets PHP 8.3 and later. OAuth2 Client Credentials authentication is implemented. The release will also support product search, lookup by ID, and retrieval of related resources with `$everything`.

The development setup and planned scope are available on GitHub. [Contributing guide](https://github.com/hrabosh/ema-upd-client/blob/main/CONTRIBUTING.md) · [Release tracker](https://github.com/hrabosh/ema-upd-client/issues/1)

The MIT license covers this project's original code. EMA data and documents are subject to their own terms.

## Authentication

Pass the four values from EMA registration to `ClientCredentials` and inject a PSR-18 HTTP client plus PSR-17 request and stream factories into `ClientCredentialsTokenProvider`. An optional PSR-20 clock controls expiry checks. Applications that manage tokens centrally can implement `AccessTokenProviderInterface` directly.

The provider reuses a valid token in memory for its own instance. It calculates usable expiry from the start of the token request using `expires_in`, minus 10% rounded down and capped at 30 seconds. A response without `expires_in` provides a token for the current call only. The provider requests another token on a later call when the cached token expires. Authentication and transport errors are exposed as `AuthenticationException` and `TransportException`.

Configure finite timeouts, TLS verification, and redirect handling on the injected transport. Use a PSR-18 `sendRequest()` implementation that returns redirects directly. The provider rejects redirect responses and makes one HTTP attempt per acquisition. Configure any application or transport logging to omit credential-bearing request bodies, authorization headers, and token responses.

## API errors

UPD HTTP failures, including redirects, expose their status via `ApiException::getStatusCode()`. Use `getRetryAfter()` to inspect a supplied `Retry-After` header and `getOperationOutcome()` to inspect a structured FHIR error when available. Use those values to decide how to handle rate limits and server errors. Each request makes one attempt.

`UnexpectedResponseException` identifies an unusable successful response. UPD operations expect HTTP 200 with a JSON object and an `application/fhir+json` or `application/json` content type. A PSR-18 sending failure raises `TransportException`.
