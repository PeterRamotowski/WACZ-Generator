<?php

namespace App\Entity;

use App\Repository\CrawledPageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CrawledPageRepository::class)]
#[ORM\Table(name: 'crawled_pages')]
class CrawledPage
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WaczRequest::class, inversedBy: 'crawledPages', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?WaczRequest $waczRequest = null;

    #[ORM\Column(length: 2000)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private ?string $url = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $title = null;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10)]
    private int $depth = 0;

    #[ORM\Column]
    private int $httpStatusCode = 200;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(nullable: true)]
    private ?int $contentLength = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_SUCCESS;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $crawledAt;

    #[ORM\Column(nullable: true)]
    private ?int $responseTime = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $headers = null;

    public function __construct()
    {
        $this->crawledAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWaczRequest(): ?WaczRequest
    {
        return $this->waczRequest;
    }

    public function setWaczRequest(?WaczRequest $waczRequest): static
    {
        $this->waczRequest = $waczRequest;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        // Ensure title doesn't exceed database column length (500 characters)
        if ($title !== null && strlen($title) > 500) {
            $title = substr($title, 0, 497) . '...';
        }
        $this->title = $title;
        return $this;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): static
    {
        $this->depth = $depth;
        return $this;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode(int $httpStatusCode): static
    {
        $this->httpStatusCode = $httpStatusCode;
        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType;
        return $this;
    }

    public function getContentLength(): ?int
    {
        return $this->contentLength;
    }

    public function setContentLength(?int $contentLength): static
    {
        $this->contentLength = $contentLength;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getCrawledAt(): \DateTimeInterface
    {
        return $this->crawledAt;
    }

    public function setCrawledAt(\DateTimeInterface $crawledAt): static
    {
        $this->crawledAt = $crawledAt;
        return $this;
    }

    public function getResponseTime(): ?int
    {
        return $this->responseTime;
    }

    public function setResponseTime(?int $responseTime): static
    {
        $this->responseTime = $responseTime;
        return $this;
    }

    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    public function setHeaders(?array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function wasSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }
}
