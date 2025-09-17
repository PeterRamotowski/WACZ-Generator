<?php

namespace App\Service\LinkExtraction;

use App\Entity\CrawledPage;

interface LinkExtractionStrategyInterface
{
    /**
     * Extract links from the given page content
     *
     * @param string $content The page content to extract links from
     * @param CrawledPage $crawledPage The crawled page entity
     * @param string $baseUrl The base URL for the domain
     * @param bool $followExternalLinks Whether to follow external links
     * @return array Array of extracted URLs
     */
    public function extractLinks(string $content, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): array;

    /**
     * Get the name of this extraction strategy
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this strategy supports the given content type
     *
     * @param string|null $contentType
     * @return bool
     */
    public function supports(?string $contentType): bool;
}