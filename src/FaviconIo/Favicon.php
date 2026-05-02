<?php

declare(strict_types=1);

namespace FaviconIo;

/**
 * Represents a favicon resource fetched from a website.
 */
class Favicon
{
    public function __construct(
        private readonly string $url,
        private readonly string $content,
        private readonly string $mimeType,
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }
}
