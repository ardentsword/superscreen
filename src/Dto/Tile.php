<?php

declare(strict_types=1);

namespace App\Dto;

use App\Tile\ContentType;
use App\Tile\Position;

/**
 * The internal tile: fully resolved and ready to store and render. Built by the
 * backend from an API-facing tile (size → footprint, then placement).
 * See docs/README.md §4.2. Serialization lives in TileRepository.
 */
readonly class Tile
{
    /**
     * @param array<string, mixed> $content type-specific payload (e.g. `src`, `text`, `html`)
     */
    public function __construct(
        private string $id,
        private ContentType $contentType,
        private array $content,
        private Position $position,
        private int $createdAt,
        private ?int $expiresAt = null,
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

    public function getPosition(): Position
    {
        return $this->position;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?int
    {
        return $this->expiresAt;
    }

    /**
     * Id of the API key that last created/updated this tile (null when auth is
     * open). Internal attribution — not exposed in the public layout.
     */
    public function getApiKeyId(): ?string
    {
        return $this->apiKeyId;
    }

    /**
     * A tile is live while it has no expiry or its expiry is still in the future.
     */
    public function isLiveAt(int $now): bool
    {
        return $this->expiresAt === null || $this->expiresAt > $now;
    }
}
