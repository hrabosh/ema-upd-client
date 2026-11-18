# Contributing

Use PHP 8.3 or later and a current Composer 2 release. Install the locked development dependencies and run the offline checks.

```sh
composer install
composer check
composer audit --locked
```

`composer check` validates Composer metadata and runs PHPUnit, PHPStan, and PHP-CS-Fixer in dry-run mode. The individual commands are `composer test`, `composer analyse`, and `composer cs:check`. Use `composer cs:fix` to apply formatting. PHPUnit fails if the test suite is empty.

Use PHP 8.3 when updating the development lock so the result installs on the minimum supported PHP version. CI tests the lock on PHP 8.3, 8.4, and 8.5 and independently resolves the lowest and highest allowed dependencies on the two ends of that matrix. Consumers resolve the package's own dependency constraints.

## Code conventions

- Write code, documentation, issues, and exception messages in English. Use `declare(strict_types=1);`, one class or interface per PSR-4 file, and explicit types for parameters, returns, and properties.
- Use `final` for concrete classes unless inheritance has a documented use. Use `readonly` for immutable values. Describe array shapes and collection types in PHPDoc and validate external input before narrowing its type.
- Compose objects through constructor injection and the PSR interfaces. Keep the public API small and mark implementation-only helpers `@internal`. Public argument names used in examples form part of the compatibility policy.
- Keep configuration in the caller. Library construction and autoloading are free of network calls. Catch specific transport and JSON exceptions and keep credentials, tokens, and authenticated requests out of fixtures and package-owned diagnostics.
- Preserve unknown FHIR fields and distinguish remote errors from malformed responses. Ordinary tests use fake transport and clocks and run without EMA credentials.

## Pull requests

Start from `main` on a short-lived branch. Link the issue and use a title prefix such as `feat:`, `fix:`, `docs:`, `test:`, or `chore:`.

- Describe the behavior, verification performed, and any compatibility impact.
- Include relevant offline tests and documentation. Verify that examples use the implemented signatures and identify which behavior was observed live.
- Run the quality checks and review credential handling, finite transport timeouts, redirects, and pagination where relevant.
- Arrange independent review of API, authentication, and pagination changes before release. Review any audit findings and record their resolution. Contributors are responsible for verifying AI-assisted changes and accurately identifying the kind of review performed.

In the `0.x` series, patch releases preserve the documented public API. Deliberate breaking changes require a new minor version and migration notes.
