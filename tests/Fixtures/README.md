# UPD response fixtures

These small JSON payloads are synthetic and were written for offline tests from the response envelopes in the public v4 OpenAPI and the companion [API contract](../../docs/api-contract.md). No file was captured from EMA. Identifiers, names, extension values and continuation data are fictional. The examples exercise the fields used by the client and omit much of the FHIR schema.

| File | Intended coverage |
| --- | --- |
| `product.json` | A product with a numeric-looking string ID and an unknown extension |
| `product-search.json` | A search Bundle with a product entry, an informational URL and search metadata |
| `product-everything.json` | A Bundle with different resource types |
| `empty-search.json` | A successful search with an explicit empty entry list |
| `next-link.json` | A search page with an opaque query-only continuation link |
| `operation-outcome.json` | A structured FHIR error example |
