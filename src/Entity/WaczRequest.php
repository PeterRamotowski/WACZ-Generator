<?php

namespace App\Entity;

use App\Repository\WaczRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WaczRequestRepository::class)]
#[ORM\Table(name: 'wacz_requests')]
class WaczRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2000)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private ?string $url = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 10)]
    private int $maxDepth = 10;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 10000)]
    private int $maxPages = 100;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Range(min: 500, max: 30000)]
    private int $crawlDelay = 1000;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\OneToMany(targetEntity: CrawledPage::class, mappedBy: 'waczRequest', orphanRemoval: true)]
    private Collection $crawledPages;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->crawledPages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function setMaxDepth(int $maxDepth): static
    {
        $this->maxDepth = $maxDepth;
        return $this;
    }

    public function getMaxPages(): int
    {
        return $this->maxPages;
    }

    public function setMaxPages(int $maxPages): static
    {
        $this->maxPages = $maxPages;
        return $this;
    }

    public function getCrawlDelay(): int
    {
        return $this->crawlDelay;
    }

    public function setCrawlDelay(int $crawlDelay): static
    {
        $this->crawlDelay = $crawlDelay;
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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;
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

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * @return Collection<int, CrawledPage>
     */
    public function getCrawledPages(): Collection
    {
        return $this->crawledPages;
    }

    public function addCrawledPage(CrawledPage $crawledPage): static
    {
        if (!$this->crawledPages->contains($crawledPage)) {
            $this->crawledPages->add($crawledPage);
            $crawledPage->setWaczRequest($this);
        }

        return $this;
    }

    public function removeCrawledPage(CrawledPage $crawledPage): static
    {
        if ($this->crawledPages->removeElement($crawledPage)) {
            // set the owning side to null (unless already changed)
            if ($crawledPage->getWaczRequest() === $this) {
                $crawledPage->setWaczRequest(null);
            }
        }

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function getDuration(): ?int
    {
        if ($this->startedAt && $this->completedAt) {
            return $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
        }
        return null;
    }

    public function getProgress(): float
    {
        $totalPages = count($this->crawledPages);
        return $this->maxPages > 0 ? min(100, ($totalPages / $this->maxPages) * 100) : 0;
    }
}
