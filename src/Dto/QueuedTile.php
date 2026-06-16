<?php

declare(strict_types=1);

namespace App\Dto;

use App\Tile\ContentType;
use App\Tile\Size;

/**
 * A tile waiting for grid space. It holds everything needed to build the
 * internal {@see Tile} once it can be placed; position and expiry are decided at
 * placement time (its TTL starts when it actually appears). See docs/BACKEND.md.
 */
final readonly class QueuedTile
{
    /**
     * @param array<string, mixed> $content type-specific payload (no `type` key)
     */
    public function __construct(
        private string $id,
        private ContentType $contentType,
        private array $content,
        private Size $size,
        private ?int $duration,
        private int $enqueuedAt,
        private ?string $apiKeyId = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getContentType(): ContentType
    {
        return $this->contentType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    public function getSize(): Size
    {
        return $this->size;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function getApiKeyId(): ?string
    {
        return $this->apiKeyId;
    }

    public function getEnqueuedAt(): int
    {
        return $this->enqueuedAt;
    }
}
