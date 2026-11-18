# Changelog

## 0.1.0

- Add a framework-independent PHP client for the EMA veterinary UPD public v4 API.
- Support OAuth2 Client Credentials with per-provider token caching and an injectable clock.
- Support product search, lookup by resource ID, and `$everything` with immutable response views and decoded FHIR payload access.
- Add explicit, one-page pagination with destination validation and preservation of the opaque continuation query.
- Expose HTTP status, `Retry-After` and structured FHIR errors through the public exception contract.
- Include a Guzzle quickstart and a bounded production smoke check.

Patch releases preserve the documented public API within the `0.x` series. Deliberate breaking changes require a new minor version and migration notes.
