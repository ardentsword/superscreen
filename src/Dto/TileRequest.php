<?php

declare(strict_types=1);

namespace App\Dto;

use App\Tile\Size;

/**
 * The API-facing tile: what callers POST to /api/tiles. It carries a footprint —
 * either a named `size` OR an explicit `width`/`height` (mutually exclusive) —
 * and a duration; the backend resolves the footprint, places the tile, and
 * computes expiry to produce the internal {@see Tile}.
 * See docs/README.md §4.1 and docs/BACKEND.md.
 */
readonly class TileRequest
{
    /**
     * @param array<string, mixed> $content  the content object: `{ "type": ..., <payload> }`
     * @param Size|null            $size      a named preset; mutually exclusive with width/height
     * @param int|null             $width     explicit footprint width (cells); requires height
     * @param int|null             $height    explicit footprint height (cells); requires width
     * @param string|null          $id        optional; the backend generates one when null/empty
     */
    public function __construct(
        private array $content,
        private ?Size $size = null,
        private ?int $width = null,
        private ?int $height = null,
        private ?string $id = null,
        private ?int $duration = null,
    ) {}

    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    public function getSize(): ?Size
    {
        return $this->size;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }
}
