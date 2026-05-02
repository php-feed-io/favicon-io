# Implementation TODO

Tasks derived from the implementation plan for PSR-18-compatible favicon discovery.
Check items off as they are completed.

## Setup

- [ ] Add `psr/http-client` (^1.0), `psr/http-factory` (^1.1), `psr/http-message` (^2.0) to `require` in `composer.json`
- [ ] Add `psr/simple-cache` (^3.0) and `psr/log` (^3.0) to `suggest` in `composer.json`
- [ ] Add PSR mock packages to `require-dev` for tests (`psr/http-client`, `psr/http-factory` interfaces available via the required packages above; add `psr/simple-cache` to `require-dev` too)
- [ ] Run `composer install` to pull in new dependencies

## Implementation

- [ ] Create `src/FaviconIo/FaviconDiscovery.php`
  - [ ] Constructor: `ClientInterface`, `RequestFactoryInterface`, optional `CacheInterface`, optional `LoggerInterface`, `$userAgent`, `$cacheTtl`, `$pageBodyCap`
  - [ ] `discover(string $baseUrl): ?string` — cache read/write wrapper around `doDiscover()`
  - [ ] `doDiscover(string $baseUrl): ?string` — orchestrate the 5-priority algorithm
  - [ ] `extractCandidatesFromHtml(string $html, string $baseUrl): array` — DOMDocument-based parser
  - [ ] `parseLargestDimension(string $sizes): int` — parse `sizes` attribute (`any` → `PHP_INT_MAX`)
  - [ ] `normaliseUrl(string $url, string $baseUrl): ?string` — handle protocol-relative, root-relative, relative, absolute
  - [ ] `fetchPageHtml(string $url): ?string` — GET via PSR-18, cap at `$pageBodyCap`
  - [ ] `headExists(string $url): bool` — HEAD via PSR-18, true iff 2xx

## Tests

- [ ] Create `tests/FaviconIo/FaviconDiscoveryTest.php`
  - [ ] `testReturnsCachedUrlWithoutHttpRequest`
  - [ ] `testNegativeCacheSentinelReturnsNull`
  - [ ] `testExpiredCacheTriggersRediscovery`
  - [ ] `testWritesDiscoveredUrlToCache`
  - [ ] `testWritesNegativeCacheWhenNothingFound`
  - [ ] `testNoCacheProvided`
  - [ ] `testAppleTouchIconTakesPriority`
  - [ ] `testAppleTouchIconPrecomposedAccepted`
  - [ ] `testLargestSizedIconPreferred`
  - [ ] `testSvgPreferredOverRasterOnTie`
  - [ ] `testSizesAnyCountsAsScalable`
  - [ ] `testRegularIconUsedAsFallback`
  - [ ] `testFaviconIcoFallback`
  - [ ] `testFaviconIcoSkippedOn404`
  - [ ] `testOgImageLastResort`
  - [ ] `testFaviconIcoPriorityOverOgImage`
  - [ ] `testProtocolRelativeUrlNormalised`
  - [ ] `testRootRelativeUrlNormalised`
  - [ ] `testAbsoluteUrlPassedThrough`
  - [ ] `testHomepageFetchFailureSkipsHtmlParsing`

## Documentation

- [ ] Update `README.md` with wiring/usage example

## Quality Gates

- [ ] `composer src:php-stan` passes at level 9
- [ ] `composer src:test` (PHPUnit) — all tests green
