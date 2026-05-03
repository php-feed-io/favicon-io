<?php

/**
 * Example: Read an RSS feed and discover its favicon
 *
 * Demonstrates how to wire feed-io and favicon-io together using:
 *  - GuzzleHttp as the shared HTTP client (PSR-18 since Guzzle 7)
 *  - Monolog as the PSR-3 logger
 *  - Symfony Cache (filesystem) as the PSR-16 cache
 *
 * Install the required packages first:
 *
 *   composer require php-feed-io/feed-io php-feed-io/favicon-io \
 *                   guzzlehttp/guzzle guzzlehttp/psr7 \
 *                   monolog/monolog symfony/cache
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use FeedIo\Adapter\Http\Client as FeedIoHttpClient;
use FeedIo\FeedIo;
use FeedIo\FaviconIo\FaviconDiscovery;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

// ---------------------------------------------------------------------------
// 1. Logger — Monolog writing DEBUG+ to stdout
// ---------------------------------------------------------------------------
$logger = new Logger('feed+favicon', [
    new StreamHandler('php://stdout', Level::Debug),
]);

// ---------------------------------------------------------------------------
// 2. HTTP client — Guzzle 7 implements PSR-18 (Psr\Http\Client\ClientInterface)
//    so the same GuzzleClient instance drives both feed-io and favicon-io.
// ---------------------------------------------------------------------------
$guzzle = new GuzzleClient([
    'timeout'         => 10,
    'connect_timeout' => 5,
    'allow_redirects' => true,   // Guzzle follows redirects automatically.
]);

// ---------------------------------------------------------------------------
// 3. feed-io — FeedIo\Adapter\Http\Client wraps any PSR-18 client.
// ---------------------------------------------------------------------------
$feedIoClient = new FeedIoHttpClient($guzzle);
$feedIo       = new FeedIo($feedIoClient, $logger);

// ---------------------------------------------------------------------------
// 4. PSR-16 cache — Symfony FilesystemAdapter, 7-day TTL.
// ---------------------------------------------------------------------------
$cache = new Psr16Cache(
    new FilesystemAdapter(
        namespace:       'favicon',
        defaultLifetime: 7 * 86400,
        directory:       sys_get_temp_dir() . '/favicon-io-cache',
    )
);

// ---------------------------------------------------------------------------
// 5. favicon-io — GuzzleHttp\Psr7\HttpFactory implements both PSR-17
//    interfaces (RequestFactoryInterface and UriFactoryInterface).
// ---------------------------------------------------------------------------
$faviconDiscovery = new FaviconDiscovery(
    httpClient:     $guzzle,           // PSR-18
    requestFactory: new HttpFactory(), // PSR-17
    cache:          $cache,            // PSR-16 (optional – skip to disable caching)
    logger:         $logger,           // PSR-3  (optional – skip to silence HTTP errors)
);

// ---------------------------------------------------------------------------
// 6. Read the feed and discover the favicon
// ---------------------------------------------------------------------------
$feedUrl = 'https://www.nasa.gov/rss/dyn/breaking_news.rss';

echo "Reading feed: {$feedUrl}\n";
$result = $feedIo->read($feedUrl);
$feed   = $result->getFeed();

echo "Feed title : " . ($feed->getTitle() ?? '(unknown)') . "\n";

// Derive the site origin (scheme + host) from the feed URL so we probe the
// right domain for the favicon.
$parsed = parse_url($feedUrl);
if (!is_array($parsed) || empty($parsed['host'])) {
    echo "Error: could not parse a valid host from feed URL '{$feedUrl}'\n";
    exit(1);
}
$siteUrl = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
if (isset($parsed['port'])) {
    $siteUrl .= ':' . $parsed['port'];
}

$faviconUrl = $faviconDiscovery->discover($siteUrl);
echo "Favicon URL: " . ($faviconUrl ?? '(none found)') . "\n\n";

// ---------------------------------------------------------------------------
// 7. Print the latest items
// ---------------------------------------------------------------------------
$count = 0;
foreach ($feed as $item) {
    $date  = $item->getLastModified()?->format(DateTimeInterface::ATOM) ?? 'n/a';
    $title = $item->getTitle() ?? '(no title)';
    echo "  [{$date}] {$title}\n";

    if (++$count >= 5) {
        break;
    }
}
