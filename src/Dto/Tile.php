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
class Tile
{
    /**
     * @param array<string, mixed> $content type-specific payload (e.g. `src`, `text`, `html`)
     */
    public function __construct(
        private readonly string $id,
        private readonly ContentType $contentType,
        private readonly array $content,
        private readonly Position $position,
        private readonly int $createdAt,
        private readonly ?int $expiresAt = null,
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
     * A tile is live while it has no expiry or its expiry is still in the future.
     */
    public function isLiveAt(int $now): bool
    {
        return $this->expiresAt === null || $this->expiresAt > $now;
    }
}
