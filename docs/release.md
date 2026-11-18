# Release checklist for 0.1.0

## Candidate verification

- [x] Run the offline workflow commands on PHP 8.3, 8.4 and 8.5. Refresh the lowest allowed dependencies on 8.3 and the highest allowed dependencies on 8.5.
- [x] Run the quickstart and smoke check from a clean PHP 8.3 consumer project installed from the candidate VCS revision. Record the production observations in the API contract.
- [x] Inspect the distribution archive for runtime code, Composer metadata, examples, README and license. Check for any local credentials, caches, vendor or tests in the archive.
- [x] Review the public API, credential handling, expiry, destination validation and error behavior independently. Record release-affecting findings and their fixes.

On 2026-11-18, all five workflow scenarios passed locally with 193 tests and 555 assertions each. The development lock installed on PHP 8.3.17, 8.4.27 and 8.5.12. Fresh lowest- and highest-dependency resolutions passed, and Composer reported zero security advisories. PHPStan and PHP-CS-Fixer passed.

A separate PHP 8.3.17 project installed candidate revision `e137c2f` from local VCS with Guzzle 7.16.3 and Guzzle PSR-7 2.13.4. Composer autoloaded the package and the project executed both installed examples against production. Git and Composer archives contained the required package and example files. [API contract and production observations](api-contract.md#local-observations)

## Review record

An independent AI reviewer inspected the public API, token expiry and credential diagnostics, destination checks, error handling, and the consumer examples on 2026-11-18.

- The OAuth provider could cache a token that the API executor rejected as an invalid Bearer value. Both boundaries now use the same Bearer syntax check. An offline regression test verifies that a malformed response leaves the provider free to acquire a valid token on the next call.
- The smoke check could mistake the first unfiltered product for evidence that `permanantIdentifier` worked. It now makes one unfiltered control request and requires the expected identifier match to differ from that control.
- Production testing revealed literal spaces in the echoed `productName` of a next link. The client encodes those spaces, preserves the opaque `_ct`, and applies its existing destination checks. The synthetic pagination suite covers this format.

## Maintainer publication

Complete these steps after candidate verification.

- [ ] Publish the reviewed source to GitHub and verify a successful GitHub Actions run on the release commit.
- [ ] Create and push the Git tag `0.1.0` on that commit. Use the first entry in the changelog as the GitHub release notes. [Changelog](../CHANGELOG.md)
- [ ] Sign in to Packagist, submit `https://github.com/hrabosh/ema-upd-client` as the repository URL, and verify that Packagist lists `hrabosh/ema-upd-client` with version `0.1.0`. Check that future tags can be refreshed through the GitHub integration. [Packagist submission](https://packagist.org/packages/submit) · [Packagist publishing guide](https://packagist.org/about#how-to-submit-packages)
- [ ] Verify a fresh install with `composer require hrabosh/ema-upd-client:^0.1` once the package is listed. Record tag, release and Packagist status in the release issue. [Issue #8](https://github.com/hrabosh/ema-upd-client/issues/8)
