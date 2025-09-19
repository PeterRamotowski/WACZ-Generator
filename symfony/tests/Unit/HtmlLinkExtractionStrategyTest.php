<?php

namespace App\Tests\Unit;

use App\Entity\CrawledPage;
use App\Entity\WaczRequest;
use App\Service\LinkExtraction\HtmlLinkExtractionStrategy;
use App\Service\UrlNormalizerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HtmlLinkExtractionStrategyTest extends TestCase
{
    private HtmlLinkExtractionStrategy $strategy;

    /** @var UrlNormalizerService&MockObject */
    private UrlNormalizerService $urlNormalizer;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->urlNormalizer = $this->createMock(UrlNormalizerService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->strategy = new HtmlLinkExtractionStrategy(
            $this->urlNormalizer,
            $this->logger
        );
    }

    public function testGetName(): void
    {
        $this->assertEquals('html_links', $this->strategy->getName());
    }

    public function testSupportsHtmlContent(): void
    {
        $this->assertTrue($this->strategy->supports('text/html'));
        $this->assertTrue($this->strategy->supports('application/xhtml+xml'));
        $this->assertFalse($this->strategy->supports('text/css'));
        $this->assertFalse($this->strategy->supports('application/javascript'));
    }

    public function testExtractLinksFromHtml(): void
    {
        $html = '
            <!DOCTYPE html>
            <html>
            <head><title>Test</title></head>
            <body>
                <a href="/page1">Page 1</a>
                <a href="https://example.com/page2">Page 2</a>
                <a href="javascript:void(0)">JavaScript Link</a>
                <a href="#anchor">Anchor</a>
            </body>
            </html>
        ';

        $waczRequest = new WaczRequest();
        $crawledPage = new CrawledPage();
        $crawledPage->setWaczRequest($waczRequest);
        $crawledPage->setUrl('https://example.com/');
        $crawledPage->setDepth(1);

        // Mock URL normalizer behavior
        $this->urlNormalizer->method('resolveUrl')
            ->willReturnCallback(function ($href, $baseUrl) {
                if ($href === '/page1') return 'https://example.com/page1';
                if ($href === 'https://example.com/page2') return 'https://example.com/page2';
                return $href;
            });

        $this->urlNormalizer->method('normalizeUrl')
            ->willReturnCallback(function ($url) {
                return $url;
            });

        $this->urlNormalizer->method('isValidUrl')
            ->willReturnCallback(function ($url, $baseUrl, $followExternalLinks) {
                return str_starts_with($url, 'https://example.com/');
            });

        $extractedUrls = $this->strategy->extractLinks(
            $html,
            $crawledPage,
            'https://example.com/',
            false
        );

        // Should extract valid HTTP/HTTPS links
        $this->assertCount(2, $extractedUrls);

        $urls = array_column($extractedUrls, 'url');
        $this->assertContains('https://example.com/page1', $urls);
        $this->assertContains('https://example.com/page2', $urls);

        // Check depth increment
        foreach ($extractedUrls as $urlData) {
            $this->assertEquals(2, $urlData['depth']); // Parent depth + 1
            $this->assertEquals('link', $urlData['type']);
        }
    }

    public function testExtractLinksHandlesEmptyContent(): void
    {
        $crawledPage = new CrawledPage();
        $crawledPage->setUrl('https://example.com/');
        $crawledPage->setDepth(0);

        $extractedUrls = $this->strategy->extractLinks(
            '',
            $crawledPage,
            'https://example.com/',
            false
        );

        $this->assertEmpty($extractedUrls);
    }

    public function testExtractLinksHandlesMalformedHtml(): void
    {
        $malformedHtml = '<a href="/page1">Page 1<a href="/page2">Page 2</a>';

        $crawledPage = new CrawledPage();
        $crawledPage->setUrl('https://example.com/');
        $crawledPage->setDepth(0);

        $this->urlNormalizer->method('resolveUrl')
            ->willReturnCallback(function ($href, $baseUrl) {
                return 'https://example.com' . $href;
            });

        $this->urlNormalizer->method('normalizeUrl')
            ->willReturnCallback(function ($url) {
                return $url;
            });

        // Should not throw exception and should still extract links
        $extractedUrls = $this->strategy->extractLinks(
            $malformedHtml,
            $crawledPage,
            'https://example.com/',
            false
        );

        $this->assertIsArray($extractedUrls);
    }
}
