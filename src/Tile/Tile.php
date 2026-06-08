<?php

declare(strict_types=1);

namespace App\Tile;

/**
 * The internal tile model: fully resolved and ready to store and render.
 * Created by the backend from an API-facing tile (size → footprint, then
 * placement). See docs/README.md §4.2.
 */
final readonly class Tile
{
    public function __construct(
        public string $id,
        public Content $content,
        public Position $position,
        public int $createdAt,
        public ?int $expiresAt = null,
    ) {}

    /**
     * A tile is live while it has no expiry or its expiry is still in the future.
     */
    public function isLiveAt(int $now): bool
    {
        return $this->expiresAt === null || $this->expiresAt > $now;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content->toArray(),
            'position' => $this->position->toArray(),
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            content: Content::fromArray((array) $data['content']),
            position: Position::fromArray((array) $data['position']),
            createdAt: (int) $data['created_at'],
            expiresAt: isset($data['expires_at']) ? (int) $data['expires_at'] : null,
        );
    }
}
