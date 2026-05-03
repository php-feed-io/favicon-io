<?php

declare(strict_types=1);

namespace FeedIo\FaviconIo;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Discovers the best favicon URL for a given website.
 *
 * Uses PSR-18 for HTTP, PSR-17 for request creation, optional PSR-16 caching
 * and optional PSR-3 logging. No framework-specific dependencies.
 *
 * Discovery priority:
 *  1. <link rel="apple-touch-icon"> / <link rel="apple-touch-icon-precomposed">
 *  2. <link rel="icon" sizes="…"> — largest first, SVG preferred on tie
 *  3. <link rel="icon"> / <link rel="shortcut icon"> without sizes
 *  4. /favicon.ico — confirmed via HEAD request
 *  5. <meta property="og:image"> — last resort
 */
class FaviconDiscovery
{
    /** Cache key prefix. */
    private const CACHE_PREFIX = 'favicon_';

    /** Negative-cache sentinel stored when nothing is found. */
    private const NEGATIVE_SENTINEL = '';

    /**
     * @param ClientInterface         $httpClient     PSR-18 HTTP client
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param CacheInterface|null     $cache          Optional PSR-16 cache
     * @param LoggerInterface|null    $logger         Optional PSR-3 logger
     * @param string                  $userAgent      User-Agent header sent with every request
     * @param int                     $cacheTtl       Cache TTL in seconds (default: 7 days)
     * @param int                     $pageBodyCap    Maximum bytes read from the homepage (default: 500 KB)
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $userAgent = 'favicon-io/1.0',
        private readonly int $cacheTtl = 7 * 86400,
        private readonly int $pageBodyCap = 512_000,
    ) {
    }

    /**
     * Return the best favicon URL for the given base URL, or null if none found.
     *
     * When a PSR-16 cache is provided the result is cached for $cacheTtl seconds.
     * An empty-string sentinel is stored for domains with no favicon so they are
     * not re-probed on every call within the TTL window.
     */
    public function discover(string $baseUrl): ?string
    {
        // Normalise trailing slashes so "https://example.com" and
        // "https://example.com/" map to the same cache key and discovery run.
        $baseUrl = rtrim($baseUrl, '/');

        if ($this->cache === null) {
            return $this->doDiscover($baseUrl);
        }

        $cacheKey = self::CACHE_PREFIX . md5($baseUrl);
        $cached   = $this->cache->get($cacheKey);

        if (is_string($cached)) {
            return $cached === self::NEGATIVE_SENTINEL ? null : $cached;
        }

        $result = $this->doDiscover($baseUrl);

        $this->cache->set($cacheKey, $result ?? self::NEGATIVE_SENTINEL, $this->cacheTtl);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Internal discovery logic
    // -------------------------------------------------------------------------

    private function doDiscover(string $baseUrl): ?string
    {
        $baseUrl  = rtrim($baseUrl, '/');
        $pageHtml = $this->fetchPageHtml($baseUrl . '/');

        $parsed = $pageHtml !== null
            ? $this->extractCandidatesFromHtml($pageHtml, $baseUrl)
            : ['priority' => [], 'ogImage' => null];

        // Priorities 1–3: links found in the page <head>.
        foreach ($parsed['priority'] as $candidate) {
            return $candidate;
        }

        // Priority 4: /favicon.ico — confirmed via HEAD.
        $faviconIcoUrl = $baseUrl . '/favicon.ico';
        if ($this->headExists($faviconIcoUrl)) {
            return $faviconIcoUrl;
        }

        // Priority 5: og:image — last resort.
        if ($parsed['ogImage'] !== null) {
            return $parsed['ogImage'];
        }

        return null;
    }

    /**
     * Parse <link> and <meta> tags from the homepage HTML and return candidates.
     *
     * @return array{priority: string[], ogImage: string|null}
     */
    private function extractCandidatesFromHtml(string $html, string $baseUrl): array
    {
        $previousState = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        $appleTouchIcons = [];
        $sizedIcons      = [];
        $regularIcons    = [];
        $ogImage         = null;

        foreach ($doc->getElementsByTagName('link') as $link) {
            /** @var \DOMElement $link */
            $rel  = strtolower(trim($link->getAttribute('rel')));
            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            if (in_array($rel, ['apple-touch-icon', 'apple-touch-icon-precomposed'], true)) {
                $appleTouchIcons[] = $href;
            } elseif (in_array($rel, ['icon', 'shortcut icon'], true)) {
                $sizes = trim($link->getAttribute('sizes'));
                if ($sizes !== '') {
                    $type = strtolower(trim($link->getAttribute('type')));
                    $sizedIcons[] = [
                        'href' => $href,
                        'dim'  => $this->parseLargestDimension($sizes),
                        'type' => $type,
                    ];
                } else {
                    $regularIcons[] = $href;
                }
            }
        }

        foreach ($doc->getElementsByTagName('meta') as $meta) {
            /** @var \DOMElement $meta */
            if (strtolower(trim($meta->getAttribute('property'))) === 'og:image') {
                $content = trim($meta->getAttribute('content'));
                if ($content !== '') {
                    $ogImage = $content;
                    break;
                }
            }
        }

        // Sort sized icons: largest first; SVG preferred over raster on tie.
        usort($sizedIcons, static function (array $a, array $b): int {
            if ($a['dim'] === $b['dim']) {
                $isSvgA = ($a['type'] === 'image/svg+xml');
                $isSvgB = ($b['type'] === 'image/svg+xml');
                if ($isSvgA && !$isSvgB) {
                    return -1;
                }
                if ($isSvgB && !$isSvgA) {
                    return 1;
                }
                return 0;
            }
            return $b['dim'] <=> $a['dim'];
        });

        // Build priority list (1–3) — at most one candidate per level.
        $priorityCandidates = [];
        if (!empty($appleTouchIcons)) {
            $abs = $this->normaliseUrl(reset($appleTouchIcons), $baseUrl);
            if ($abs !== null) {
                $priorityCandidates[] = $abs;
            }
        }
        if (!empty($sizedIcons)) {
            $abs = $this->normaliseUrl($sizedIcons[0]['href'], $baseUrl);
            if ($abs !== null) {
                $priorityCandidates[] = $abs;
            }
        }
        if (!empty($regularIcons)) {
            $abs = $this->normaliseUrl(reset($regularIcons), $baseUrl);
            if ($abs !== null) {
                $priorityCandidates[] = $abs;
            }
        }

        $ogImageAbs = $ogImage !== null ? $this->normaliseUrl($ogImage, $baseUrl) : null;

        return ['priority' => $priorityCandidates, 'ogImage' => $ogImageAbs];
    }

    /**
     * Return the largest pixel dimension from an HTML sizes attribute value.
     *
     * "any" maps to PHP_INT_MAX (scalable / SVG).
     */
    private function parseLargestDimension(string $sizes): int
    {
        $max = 0;
        foreach (explode(' ', strtolower($sizes)) as $token) {
            if ($token === 'any') {
                return PHP_INT_MAX;
            }
            if (preg_match('/(\d+)x(\d+)/', $token, $m)) {
                $max = max($max, (int) $m[1], (int) $m[2]);
            }
        }
        return $max;
    }

    /**
     * Normalise a raw href to an absolute URL relative to $baseUrl.
     *
     * Returns null when the URL cannot be resolved.
     */
    private function normaliseUrl(string $url, string $baseUrl): ?string
    {
        if ($url === '') {
            return null;
        }

        // Protocol-relative.
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';
            return $scheme . ':' . $url;
        }

        // Already absolute.
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Root-relative path.
        if (str_starts_with($url, '/')) {
            $parts = parse_url($baseUrl);
            if ($parts === false || !isset($parts['host'])) {
                return null;
            }
            $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            return $origin . $url;
        }

        // Relative to base URL.
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Fetch the homepage HTML, capped at $pageBodyCap bytes.
     * Returns null on non-2xx status or any network/parse error.
     */
    private function fetchPageHtml(string $url): ?string
    {
        try {
            $request  = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('User-Agent', $this->userAgent)
                ->withHeader('Accept', 'text/html');

            $response   = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                return null;
            }

            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            return $stream->read($this->pageBodyCap);
        } catch (\Throwable $e) {
            $this->logger?->debug(
                'FaviconDiscovery: could not fetch homepage {url}: {error}',
                ['url' => $url, 'error' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Perform a HEAD request and return true only when the response is 2xx.
     */
    private function headExists(string $url): bool
    {
        try {
            $request  = $this->requestFactory->createRequest('HEAD', $url)
                ->withHeader('User-Agent', $this->userAgent);

            $response = $this->httpClient->sendRequest($request);
            $status   = $response->getStatusCode();
            return $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            $this->logger?->debug(
                'FaviconDiscovery: HEAD request failed for {url}: {error}',
                ['url' => $url, 'error' => $e->getMessage()]
            );
            return false;
        }
    }
}
