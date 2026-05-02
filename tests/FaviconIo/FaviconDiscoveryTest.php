<?php

declare(strict_types=1);

namespace FeedIo\FaviconIo;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

class FaviconDiscoveryTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private Psr17Factory $psr17Factory;
    private CacheInterface&MockObject $cache;
    private FaviconDiscovery $discovery;

    protected function setUp(): void
    {
        $this->httpClient   = $this->createMock(ClientInterface::class);
        $this->psr17Factory = new Psr17Factory();
        $this->cache        = $this->createMock(CacheInterface::class);

        $this->discovery = new FaviconDiscovery(
            httpClient:     $this->httpClient,
            requestFactory: $this->psr17Factory,
            cache:          $this->cache,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeHtmlResponse(string $html, int $status = 200): ResponseInterface
    {
        return new Response($status, [], $html);
    }

    private function makeHeadResponse(int $status): ResponseInterface
    {
        return new Response($status);
    }

    /**
     * Stub the HTTP client: first call (GET homepage) returns $pageResponse,
     * second call (HEAD /favicon.ico) returns $headResponse.
     */
    private function stubRequests(ResponseInterface $pageResponse, ?ResponseInterface $headResponse = null): void
    {
        if ($headResponse === null) {
            $this->httpClient
                ->expects($this->once())
                ->method('sendRequest')
                ->willReturn($pageResponse);
            return;
        }

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use ($pageResponse, $headResponse): ResponseInterface {
                return $request->getMethod() === 'HEAD' ? $headResponse : $pageResponse;
            });
    }

    // -------------------------------------------------------------------------
    // Caching
    // -------------------------------------------------------------------------

    public function testReturnsCachedUrlWithoutHttpRequest(): void
    {
        $this->cache->method('get')->willReturn('https://example.com/cached.ico');
        $this->httpClient->expects($this->never())->method('sendRequest');

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/cached.ico', $result);
    }

    public function testNegativeCacheSentinelReturnsNull(): void
    {
        $this->cache->method('get')->willReturn(''); // empty string = sentinel
        $this->httpClient->expects($this->never())->method('sendRequest');

        $result = $this->discovery->discover('https://example.com');

        $this->assertNull($result);
    }

    public function testWritesDiscoveredUrlToCache(): void
    {
        $this->cache->method('get')->willReturn(null);

        $html = '<html><head><link rel="icon" href="/icon.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $this->cache
            ->expects($this->once())
            ->method('set')
            ->with(
                'favicon_' . md5('https://example.com'),
                'https://example.com/icon.png',
                $this->isType('int')
            );

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/icon.png', $result);
    }

    public function testWritesNegativeCacheWhenNothingFound(): void
    {
        $this->cache->method('get')->willReturn(null);

        // GET → 404, HEAD → 404 → nothing found.
        $this->httpClient
            ->method('sendRequest')
            ->willReturn($this->makeHtmlResponse('', 404));

        $this->cache
            ->expects($this->once())
            ->method('set')
            ->with(
                'favicon_' . md5('https://example.com'),
                '',  // negative sentinel
                $this->isType('int')
            );

        $result = $this->discovery->discover('https://example.com');

        $this->assertNull($result);
    }

    public function testExpiredCacheTriggersRediscovery(): void
    {
        // Simulate expired cache: get() returns null (TTL already handled by PSR-16).
        $this->cache->method('get')->willReturn(null);

        $html = '<html><head><link rel="icon" href="/new.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $this->cache->expects($this->once())->method('set');

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/new.png', $result);
    }

    public function testNoCacheProvided(): void
    {
        // Build a discovery instance without any cache.
        $discovery = new FaviconDiscovery(
            httpClient:     $this->httpClient,
            requestFactory: $this->psr17Factory,
        );

        $html = '<html><head><link rel="icon" href="/icon.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        // Two calls → two HTTP requests (no caching).
        $result1 = $discovery->discover('https://example.com');
        $result2 = $discovery->discover('https://example.com');

        $this->assertSame('https://example.com/icon.png', $result1);
        $this->assertSame('https://example.com/icon.png', $result2);
    }

    // -------------------------------------------------------------------------
    // Priority 1: apple-touch-icon
    // -------------------------------------------------------------------------

    public function testAppleTouchIconTakesPriority(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = <<<HTML
        <html><head>
          <link rel="apple-touch-icon" href="/touch.png">
          <link rel="icon" sizes="64x64" href="/icon-64.png">
          <link rel="shortcut icon" href="/favicon.ico">
        </head></html>
        HTML;

        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/touch.png', $result);
    }

    public function testAppleTouchIconPrecomposedAccepted(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><link rel="apple-touch-icon-precomposed" href="/precomposed.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/precomposed.png', $result);
    }

    // -------------------------------------------------------------------------
    // Priority 2: sized icons
    // -------------------------------------------------------------------------

    public function testLargestSizedIconPreferred(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = <<<HTML
        <html><head>
          <link rel="icon" sizes="16x16" href="/icon-16.png">
          <link rel="icon" sizes="64x64" href="/icon-64.png">
          <link rel="icon" sizes="32x32" href="/icon-32.png">
        </head></html>
        HTML;

        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/icon-64.png', $result);
    }

    public function testSvgPreferredOverRasterOnTie(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = <<<HTML
        <html><head>
          <link rel="icon" sizes="32x32" href="/icon.png" type="image/png">
          <link rel="icon" sizes="32x32" href="/icon.svg" type="image/svg+xml">
        </head></html>
        HTML;

        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/icon.svg', $result);
    }

    public function testSizesAnyCountsAsScalable(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = <<<HTML
        <html><head>
          <link rel="icon" sizes="any" href="/icon.svg" type="image/svg+xml">
          <link rel="icon" sizes="256x256" href="/icon-256.png">
        </head></html>
        HTML;

        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/icon.svg', $result);
    }

    // -------------------------------------------------------------------------
    // Priority 3: regular icon (no sizes)
    // -------------------------------------------------------------------------

    public function testRegularIconUsedAsFallback(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><link rel="shortcut icon" href="/favicon.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/favicon.png', $result);
    }

    // -------------------------------------------------------------------------
    // Priority 4: /favicon.ico fallback
    // -------------------------------------------------------------------------

    public function testFaviconIcoFallback(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><title>No icons here</title></head></html>';

        $this->stubRequests(
            $this->makeHtmlResponse($html),
            $this->makeHeadResponse(200),
        );

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/favicon.ico', $result);
    }

    public function testFaviconIcoSkippedOn404(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head></head></html>';

        $this->stubRequests(
            $this->makeHtmlResponse($html),
            $this->makeHeadResponse(404),
        );

        $result = $this->discovery->discover('https://example.com');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Priority 5: og:image
    // -------------------------------------------------------------------------

    public function testOgImageLastResort(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><meta property="og:image" content="https://example.com/og.jpg"></head></html>';

        $this->stubRequests(
            $this->makeHtmlResponse($html),
            $this->makeHeadResponse(404), // /favicon.ico absent
        );

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/og.jpg', $result);
    }

    public function testFaviconIcoPriorityOverOgImage(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><meta property="og:image" content="https://example.com/og.jpg"></head></html>';

        $this->stubRequests(
            $this->makeHtmlResponse($html),
            $this->makeHeadResponse(200), // /favicon.ico present
        );

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/favicon.ico', $result);
    }

    // -------------------------------------------------------------------------
    // URL normalisation
    // -------------------------------------------------------------------------

    public function testProtocolRelativeUrlNormalised(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><link rel="icon" href="//cdn.example.com/icon.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://cdn.example.com/icon.png', $result);
    }

    public function testRootRelativeUrlNormalised(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><link rel="icon" href="/assets/favicon.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/assets/favicon.png', $result);
    }

    public function testAbsoluteUrlPassedThrough(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        $html = '<html><head><link rel="icon" href="https://assets.example.com/icon.png"></head></html>';
        $this->httpClient->method('sendRequest')->willReturn($this->makeHtmlResponse($html));

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://assets.example.com/icon.png', $result);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testHomepageFetchFailureSkipsHtmlParsing(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');

        // GET throws → fetchPageHtml returns null → skip to /favicon.ico HEAD.
        $this->httpClient
            ->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): ResponseInterface {
                if ($request->getMethod() === 'GET') {
                    throw new \RuntimeException('Connection refused');
                }
                return $this->makeHeadResponse(200);
            });

        $result = $this->discovery->discover('https://example.com');

        $this->assertSame('https://example.com/favicon.ico', $result);
    }
}
