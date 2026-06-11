<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Dto\Tile;
use App\Dto\TileRequest;
use App\Dto\TileUpsertResult;
use App\Service\Placement\NoSpaceException;
use App\Service\Placement\TilePlacer;
use App\Service\SimpleDatabase\TileRepository;
use App\Tile\ContentType;
use App\Tile\Position;

/**
 * Application service for layout mutations: turns an API-facing TileRequest into
 * a placed, persisted internal Tile (resolve content type, size → footprint →
 * position, compute expiry, store). The controller only maps HTTP ↔ this service.
 */
final readonly class LayoutService
{
    public function __construct(
        private TileRepository $tiles,
        private TilePlacer $placer,
    ) {}

    /**
     * Create or replace a tile from an API request.
     *
     * @param int $now current unix time (seconds)
     *
     * @throws UnknownContentTypeException when content.type is unknown/missing
     * @throws NoSpaceException            when the grid has no room
     */
    public function upsert(TileRequest $request, int $now): TileUpsertResult
    {
        $content = $request->getContent();
        $type = $content['type'] ?? null;
        $contentType = ContentType::tryFrom(\is_string($type) ? $type : '');
        if ($contentType === null) {
            throw UnknownContentTypeException::for(\is_string($type) ? $type : null);
        }
        unset($content['type']);

        // id is optional: generate a hashed one when missing/empty.
        $id = $request->getId();
        $id = ($id === null || $id === '') ? self::generateId() : $id;

        $existing = $this->tiles->find($id);
        $size = $request->getSize();

        // Keep the current position on re-post when the footprint is unchanged,
        // so the screen doesn't reshuffle; otherwise place it fresh.
        $position = ($existing !== null
            && $existing->getPosition()->w === $size->width()
            && $existing->getPosition()->h === $size->height())
            ? $existing->getPosition()
            : $this->placer->place($size, $this->occupiedExcept($id, $now));

        $duration = $request->getDuration();
        $tile = new Tile(
            id: $id,
            contentType: $contentType,
            content: $content,
            position: $position,
            createdAt: $existing?->getCreatedAt() ?? $now,
            expiresAt: $duration === null ? null : $now + $duration,
        );
        $this->tiles->store($tile);

        return new TileUpsertResult($tile, $existing === null);
    }

    /**
     * A generated tile id: a truncated SHA-256 hex string of random bytes.
     */
    private static function generateId(): string
    {
        return substr(hash('sha256', random_bytes(16)), 0, 16);
    }

    /**
     * Live tile positions, excluding the tile being upserted so it can reuse
     * its own cells.
     *
     * @return list<Position>
     */
    private function occupiedExcept(string $id, int $now): array
    {
        $occupied = [];
        foreach ($this->tiles->findLive($now) as $live) {
            if ($live->getId() !== $id) {
                $occupied[] = $live->getPosition();
            }
        }

        return $occupied;
    }
}
