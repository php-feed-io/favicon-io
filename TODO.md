# Implementation TODO

Tasks derived from the implementation plan for PSR-18-compatible favicon discovery.
Check items off as they are completed.

## Setup

- [x] Add `psr/http-client` (^1.0), `psr/http-factory` (^1.1), `psr/http-message` (^2.0) to `require` in `composer.json`
- [x] Add `psr/simple-cache` (^3.0) and `psr/log` (^3.0) to `suggest` in `composer.json`
- [x] Add PSR mock packages to `require-dev` for tests (`psr/http-client`, `psr/http-factory` interfaces available via the required packages above; add `psr/simple-cache` to `require-dev` too)
- [x] Run `composer install` to pull in new dependencies

## Implementation

- [x] Create `src/FaviconIo/FaviconDiscovery.php`
  - [x] Constructor: `ClientInterface`, `RequestFactoryInterface`, optional `CacheInterface`, optional `LoggerInterface`, `$userAgent`, `$cacheTtl`, `$pageBodyCap`
  - [x] `discover(string $baseUrl): ?string` — cache read/write wrapper around `doDiscover()`
  - [x] `doDiscover(string $baseUrl): ?string` — orchestrate the 5-priority algorithm
  - [x] `extractCandidatesFromHtml(string $html, string $baseUrl): array` — DOMDocument-based parser
  - [x] `parseLargestDimension(string $sizes): int` — parse `sizes` attribute (`any` → `PHP_INT_MAX`)
  - [x] `normaliseUrl(string $url, string $baseUrl): ?string` — handle protocol-relative, root-relative, relative, absolute
  - [x] `fetchPageHtml(string $url): ?string` — GET via PSR-18, cap at `$pageBodyCap`
  - [x] `headExists(string $url): bool` — HEAD via PSR-18, true iff 2xx

## Tests

- [x] Create `tests/FaviconIo/FaviconDiscoveryTest.php`
  - [x] `testReturnsCachedUrlWithoutHttpRequest`
  - [x] `testNegativeCacheSentinelReturnsNull`
  - [x] `testExpiredCacheTriggersRediscovery`
  - [x] `testWritesDiscoveredUrlToCache`
  - [x] `testWritesNegativeCacheWhenNothingFound`
  - [x] `testNoCacheProvided`
  - [x] `testAppleTouchIconTakesPriority`
  - [x] `testAppleTouchIconPrecomposedAccepted`
  - [x] `testLargestSizedIconPreferred`
  - [x] `testSvgPreferredOverRasterOnTie`
  - [x] `testSizesAnyCountsAsScalable`
  - [x] `testRegularIconUsedAsFallback`
  - [x] `testFaviconIcoFallback`
  - [x] `testFaviconIcoSkippedOn404`
  - [x] `testOgImageLastResort`
  - [x] `testFaviconIcoPriorityOverOgImage`
  - [x] `testProtocolRelativeUrlNormalised`
  - [x] `testRootRelativeUrlNormalised`
  - [x] `testAbsoluteUrlPassedThrough`
  - [x] `testHomepageFetchFailureSkipsHtmlParsing`

## Documentation

- [x] Update `README.md` with wiring/usage example

## Quality Gates

- [x] `composer src:php-stan` passes at level 9
- [x] `composer src:test` (PHPUnit) — all tests green
