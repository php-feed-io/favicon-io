# favicon-io
[![Packagist](https://img.shields.io/packagist/v/php-feed-io/favicon-io.svg)](https://packagist.org/packages/php-feed-io/favicon-io)

A PHP library for discovering favicons from websites, built on [PSR-18 (HTTP Client)](https://www.php-fig.org/psr/psr-18/).

## Features

- **Priority-ordered discovery**: apple-touch-icon → sized icons (largest first, SVG preferred) → regular icon → `/favicon.ico` → `og:image`
- **PSR-18 / PSR-17 compatible** — plug any HTTP client (Guzzle, Symfony, etc.)
- **Optional PSR-16 caching** — avoid re-probing the same domain on every call
- **Optional PSR-3 logging** — debug-level messages for HTTP failures
- **No framework dependencies**

## Installation

```bash
composer require php-feed-io/favicon-io
```

You also need a PSR-18 HTTP client and PSR-17 request factory. For example, using [Nyholm/psr7](https://github.com/Nyholm/psr7) and [Symfony HTTP Client](https://symfony.com/doc/current/http_client.html):

```bash
composer require nyholm/psr7 symfony/http-client
```

## Usage

```php
use FeedIo\FaviconIo\FaviconDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\Psr18Client;

$psr17Factory = new Psr17Factory();
$httpClient   = new Psr18Client();

$discovery = new FaviconDiscovery(
    httpClient:     $httpClient,
    requestFactory: $psr17Factory,
);

$faviconUrl = $discovery->discover('https://example.com');
// Returns a URL string, or null if no favicon was found.
```

### With caching (PSR-16)

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$cache = new Psr16Cache(new FilesystemAdapter());

$discovery = new FaviconDiscovery(
    httpClient:     $httpClient,
    requestFactory: $psr17Factory,
    cache:          $cache,           // PSR-16 CacheInterface
    cacheTtl:       7 * 86400,        // seconds; default is 7 days
);
```

### With logging (PSR-3)

```php
$discovery = new FaviconDiscovery(
    httpClient:     $httpClient,
    requestFactory: $psr17Factory,
    logger:         $psrLogger,       // PSR-3 LoggerInterface
);
```

## Discovery priority

| Priority | Source |
|---|---|
| 1 | `<link rel="apple-touch-icon">` / `<link rel="apple-touch-icon-precomposed">` |
| 2 | `<link rel="icon" sizes="…">` — largest dimension wins; SVG preferred on tie; `sizes="any"` counts as scalable |
| 3 | `<link rel="icon">` / `<link rel="shortcut icon">` — without `sizes` attribute |
| 4 | `/favicon.ico` — confirmed via HEAD request (2xx required) |
| 5 | `<meta property="og:image">` — last resort |

## Requirements

- PHP 8.2+
- PSR-18 HTTP client implementation
- PSR-17 request factory implementation
