<?php

declare(strict_types=1);

namespace FaviconIo;

use PHPUnit\Framework\TestCase;

class FaviconTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $favicon = new Favicon(
            url: 'https://example.com/favicon.ico',
            content: 'binary-content',
            mimeType: 'image/x-icon',
        );

        $this->assertSame('https://example.com/favicon.ico', $favicon->getUrl());
        $this->assertSame('binary-content', $favicon->getContent());
        $this->assertSame('image/x-icon', $favicon->getMimeType());
    }
}
