# Veterinary UPD public API v4

## Documented contract

This client targets the read-only public Union Product Database (UPD) API for veterinary medicines. Human Product Management Service (PMS) contracts and restricted UPD operations for national competent authorities (NCA) or marketing authorisation holders (MAH) use different paths, access rules and data contracts.

The production base URI is `https://spor.azure-api.net/upd/public/v4/`. The [public OpenAPI YAML](https://spor.azure-api.net/upd/api/dmz/openapi/PUBLIC-API-definition), retrieved 2026-11-18, declares OpenAPI **3.0.3** and document version **1.7.2631-0**. Its SHA-256 is `6a0532b7aff6309817ea462f28380d593ba3ead8b6b0a703b7aca75b8efe1e6e`. It defines full v4 paths and a Bearer header. It omits `servers` and an OAuth security scheme, and its response schemas are generic objects with an empty `components.schemas`. Review changes to the upstream version or hash against the assumptions below before updating this baseline. [EMA Swagger UI](https://prod-upd-openapi-app.azurewebsites.net/?urls.primaryName=PUBLIC+API)

The Vet EU implementation guide, chapter 5, version 4 (2026-03-16), names **FHIR 4.4.0 (R5 Preview #2)**. Its examples cover several audiences and API versions. The public OpenAPI defines the v4 wire paths used by this client. The response views consume only the fields they need and preserve other decoded fields. [EMA Vet EU IG, chapter 5](https://www.ema.europa.eu/system/files/documents/regulatory-procedural-guideline/vet-eu-ig_chapter-5_technical-specifications-en.pdf)

Send `Authorization: Bearer <access token>` and `Accept: application/fhir+json` for the following requests. The documented success status is HTTP 200. The published JSON examples advertise `application/fhir+json; charset=utf-8`.

| Method | Path relative to the production base | Documented success payload |
| --- | --- | --- |
| GET | `MedicinalProductDefinition` | `Bundle` of type `searchset` |
| GET | `MedicinalProductDefinition/{id}` | `MedicinalProductDefinition` |
| GET | `MedicinalProductDefinition/{id}/$everything` | `Bundle` of the product and related resources, described as `searchset` |

`{id}` is the product resource ID. Product resource IDs, permanent identifiers, other identifiers and terminology codes remain strings, including numeric-looking values.

### Search parameters

The public OpenAPI declares each query parameter optional and of type string. Descriptions below identify their purpose where documented or indicated by the name. Their production semantics remain to be verified.

| Exact key | Purpose or schema example |
| --- | --- |
| `productName` | Product name |
| `permanantIdentifier` | Permanent identifier, with the spelling published in the schema |
| `productIdentifier` | Product identifier |
| `productOwner` | Product owner |
| `authorizationNumber` | Authorisation number |
| `atcVetCode` | ATCvet code |
| `authorizationStatus` | Authorisation status |
| `procedureType` | Procedure type |
| `authorizationCountry` | Authorisation country |
| `targetSpecies` | Target species |
| `_ct` | Continuation parameter |
| `_count` | Requested page size |
| `_summary` | Summary parameter |
| `_lastUpdated` | Last update timestamp filter, with example value `gt2025-10-10` |

### OAuth2 Client Credentials

Use the client ID, client secret, OAuth2 token endpoint and scope from the UPD registration confirmation. The client sends a form-encoded POST to that endpoint with content type `application/x-www-form-urlencoded` and fields `grant_type=client_credentials`, `client_id`, `client_secret` and `scope`. Supply the exact registered endpoint and scope. Keep credentials in the request body and out of URLs. A successful token response is expected to provide a Bearer access token. The client uses an expiry supplied in the response for in-memory reuse. EMA does not guarantee a token lifetime in the cited UPD materials. [EMA registration guide](https://www.ema.europa.eu/en/documents/other/upd-registration-guide-ui-api-users_en.pdf) · [Microsoft Client Credentials protocol](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow)

### DocumentReference discrepancy

The public Swagger introduction excludes documents. Its document search operation lists `_id` and requires `Ocp-Apim-Subscription-Key`. The document lookup lists Bearer authentication. The March 2026 technical guide lists public document search and lookup using v3 example paths and different search filters, including `related` and `type`. Public document operations remain outside `0.1.0`. The client does not follow attachment URLs. [Public OpenAPI YAML](https://spor.azure-api.net/upd/api/dmz/openapi/PUBLIC-API-definition) · [EMA Vet EU IG, chapter 5, section 1.5](https://www.ema.europa.eu/system/files/documents/regulatory-procedural-guideline/vet-eu-ig_chapter-5_technical-specifications-en.pdf)

## Local observations

On 2026-11-18 the public OpenAPI download matched the version and SHA-256 above. The early authenticated token → search → product check was unavailable because the local environment lacked UPD registration credentials. Authenticated response and filter behavior remain unverified. The synthetic fixtures support offline development. [Fixture provenance](https://github.com/hrabosh/ema-upd-client/tree/main/tests/Fixtures)

For the early check, use an existing HTTP tool with the registration values supplied locally. Acquire a token, send a search using a known product or filter, then request one returned resource ID. Record status, content type, resource type and whether the filter behaved as expected. Avoid saving secrets, tokens, complete responses or continuation values in the repository or issue comments.

## Open checks for release

- [ ] Confirm the token request against EMA and record only the status and response field names relevant to authentication and expiry.
- [ ] Search with a known product and check the status, media type, Bundle type and shape, including the ID field needed for lookup. Use that ID for lookup and `$everything`. Record the returned resource types and optional envelope fields used by the client.
- [ ] Verify the exact `permanantIdentifier` spelling and the filters used in the quickstart against known matches. Record observed matching behavior and code formats for the filters exercised.
- [ ] Use a search known to yield multiple pages. Record the form of the next link and follow one server-supplied link unchanged. A search that yields no next link leaves pagination unverified. Continuation lifetime and page-size limits remain unknown.
- [ ] Record an error envelope if one occurs during the bounded check. Verify HTTP status handling and optional `OperationOutcome` offline when live evidence is unavailable. Published endpoint statuses do not establish a uniform production error body or numerical rate limits.
- [ ] Record the date, environment, API and document versions, operations, statuses, media and resource types, and outstanding differences. Where live behavior differs from this baseline, update this document and the owning issue before validating public signatures or examples. Add a minimal synthetic regression fixture when needed.

The authenticated release checks are tracked in [issue #8](https://github.com/hrabosh/ema-upd-client/issues/8).
