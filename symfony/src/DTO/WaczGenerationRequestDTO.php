<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class WaczGenerationRequestDTO
{
    #[Assert\NotBlank(message: 'url.not_blank')]
    #[Assert\Url(message: 'url.invalid')]
    #[Assert\Length(max: 2000, maxMessage: 'url.too_long')]
    public string $url = '';

    #[Assert\NotBlank(message: 'title.not_blank')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'title.too_short',
        maxMessage: 'title.too_long'
    )]
    public string $title = '';

    #[Assert\Length(
        max: 1000,
        maxMessage: 'description.too_long'
    )]
    public ?string $description = null;

    #[Assert\NotNull(message: 'max_depth.not_null')]
    #[Assert\Range(
        min: 1,
        max: 10,
        notInRangeMessage: 'max_depth.not_in_range'
    )]
    public int $maxDepth = 10;

    #[Assert\NotNull(message: 'max_pages.not_null')]
    #[Assert\Range(
        min: 1,
        max: 10000,
        notInRangeMessage: 'max_pages.not_in_range'
    )]
    public int $maxPages = 100;

    #[Assert\NotNull(message: 'crawl_delay.not_null')]
    #[Assert\Range(
        min: 500,
        max: 30000,
        notInRangeMessage: 'crawl_delay.not_in_range'
    )]
    public int $crawlDelay = 1000;

    #[Assert\Type('bool')]
    public bool $followExternalLinks = false;

    #[Assert\Type('bool')]
    public bool $includeImages = true;

    #[Assert\Type('bool')]
    public bool $includeCSS = true;

    #[Assert\Type('bool')]
    public bool $includeJS = true;

    #[Assert\All([
        new Assert\Url(message: 'exclude_urls.invalid')
    ])]
    public array $excludeUrls = [];

    #[Assert\All([
        new Assert\Regex(
            pattern: '/^[a-zA-Z0-9\*\/\-\_\.]+$/',
            message: 'exclude_patterns.invalid'
        )
    ])]
    public array $excludePatterns = [];

    public function __construct()
    {
        $this->excludeUrls = [];
        $this->excludePatterns = [];
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = trim($url);
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description ? trim($description) : null;
        return $this;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function setMaxDepth(int $maxDepth): self
    {
        $this->maxDepth = $maxDepth;
        return $this;
    }

    public function getMaxPages(): int
    {
        return $this->maxPages;
    }

    public function setMaxPages(int $maxPages): self
    {
        $this->maxPages = $maxPages;
        return $this;
    }

    public function getCrawlDelay(): int
    {
        return $this->crawlDelay;
    }

    public function setCrawlDelay(int $crawlDelay): self
    {
        $this->crawlDelay = $crawlDelay;
        return $this;
    }

    public function isFollowExternalLinks(): bool
    {
        return $this->followExternalLinks;
    }

    public function setFollowExternalLinks(bool $followExternalLinks): self
    {
        $this->followExternalLinks = $followExternalLinks;
        return $this;
    }

    public function isIncludeImages(): bool
    {
        return $this->includeImages;
    }

    public function setIncludeImages(bool $includeImages): self
    {
        $this->includeImages = $includeImages;
        return $this;
    }

    public function isIncludeCSS(): bool
    {
        return $this->includeCSS;
    }

    public function setIncludeCSS(bool $includeCSS): self
    {
        $this->includeCSS = $includeCSS;
        return $this;
    }

    public function isIncludeJS(): bool
    {
        return $this->includeJS;
    }

    public function setIncludeJS(bool $includeJS): self
    {
        $this->includeJS = $includeJS;
        return $this;
    }

    public function getExcludeUrls(): array
    {
        return $this->excludeUrls;
    }

    public function setExcludeUrls(array $excludeUrls): self
    {
        $this->excludeUrls = array_filter(array_map('trim', $excludeUrls));
        return $this;
    }

    public function getExcludePatterns(): array
    {
        return $this->excludePatterns;
    }

    public function setExcludePatterns(array $excludePatterns): self
    {
        $this->excludePatterns = array_filter(array_map('trim', $excludePatterns));
        return $this;
    }

    public function getDomain(): ?string
    {
        if (empty($this->url)) {
            return null;
        }

        $parsed = parse_url($this->url);
        return $parsed['host'] ?? null;
    }

    public function getNormalizedUrl(): string
    {
        $url = trim($this->url);
        
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }
        
        return rtrim($url, '/');
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'maxDepth' => $this->maxDepth,
            'maxPages' => $this->maxPages,
            'crawlDelay' => $this->crawlDelay,
            'followExternalLinks' => $this->followExternalLinks,
            'includeImages' => $this->includeImages,
            'includeCSS' => $this->includeCSS,
            'includeJS' => $this->includeJS,
            'excludeUrls' => $this->excludeUrls,
            'excludePatterns' => $this->excludePatterns,
        ];
    }
}
