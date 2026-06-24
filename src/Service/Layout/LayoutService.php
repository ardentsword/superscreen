<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Dto\QueuedTile;
use App\Dto\Tile;
use App\Dto\TileRequest;
use App\Dto\TileUpsertResult;
use App\Service\Placement\NoSpaceException;
use App\Service\Placement\TilePlacer;
use App\Service\SimpleDatabase\QueueRepository;
use App\Service\SimpleDatabase\ReservationRepository;
use App\Service\SimpleDatabase\TileRepository;
use App\Tile\ContentType;
use App\Tile\Position;
use App\Tile\Size;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Application service for layout mutations: turns an API-facing TileRequest into
 * a placed, persisted internal Tile (resolve content type, size → footprint →
 * position, compute expiry). When the grid is full the tile is queued instead,
 * and the queue is drained (greedily, FIFO) whenever space frees up. The
 * controller only maps HTTP ↔ this service.
 */
final readonly class LayoutService
{
    public function __construct(
        private TileRepository $tiles,
        private QueueRepository $queue,
        private ReservationRepository $reservations,
        private TilePlacer $placer,
        #[Autowire('%app.limits.max_queue%')] private int $maxQueue = 50,
        #[Autowire('%app.limits.max_content_bytes%')] private int $maxContentBytes = 262144,
        #[Autowire('%app.limits.max_id_length%')] private int $maxIdLength = 128,
        #[Autowire('%app.limits.max_tile_area%')] private int $maxTileArea = 9,
        #[Autowire('%app.limits.max_reservations%')] private int $maxReservations = 30,
    ) {}

    /**
     * Create or replace a tile from an API request.
     *
     * @param int $now current unix time (seconds)
     *
     * @throws UnknownContentTypeException when content.type is unknown/missing
     * @throws TileLimitException          when an id/content/queue limit is exceeded
     * @throws NoSpaceException            when the tile can never fit (larger than the grid)
     */
    public function upsert(TileRequest $request, int $now, ?string $apiKeyId = null): TileUpsertResult
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

        // Bound resource use (the API is a public write surface).
        if (mb_strlen($id) > $this->maxIdLength) {
            throw TileLimitException::idTooLong($this->maxIdLength);
        }
        if (\strlen((string) json_encode($content)) > $this->maxContentBytes) {
            throw TileLimitException::contentTooLarge($this->maxContentBytes);
        }

        // Footprint comes from a named size OR explicit width/height (exclusive).
        [$w, $h] = $this->resolveFootprint($request);

        // Drop expired tiles so storage can't grow unbounded over time.
        $this->tiles->pruneExpired($now);

        $existing = $this->tiles->find($id);

        try {
            $position = $this->resolvePosition($id, $w, $h, $existing, $now);
        } catch (NoSpaceException $e) {
            if ($e->permanent) {
                throw $e; // larger than the grid — queuing would never help
            }

            // No room right now: queue it (an id is placed XOR queued).
            // Cap the queue so it can't grow without bound; re-queuing an
            // already-queued id is always allowed (it just updates).
            if ($this->queue->find($id) === null && $this->queue->count() >= $this->maxQueue) {
                throw TileLimitException::queueFull($this->maxQueue);
            }

            $this->tiles->delete($id);
            $this->queue->enqueue(new QueuedTile(
                id: $id,
                contentType: $contentType,
                content: $content,
                width: $w,
                height: $h,
                duration: $request->getDuration(),
                enqueuedAt: $this->queue->find($id)?->getEnqueuedAt() ?? $now,
                apiKeyId: $apiKeyId,
            ));

            return new TileUpsertResult($id, null, false, true);
        }

        $duration = $request->getDuration();
        $tile = new Tile(
            id: $id,
            contentType: $contentType,
            content: $content,
            position: $position,
            // Each upsert restarts the lifetime (expiresAt below is recomputed as
            // now + duration), so createdAt must restart too — otherwise the
            // window createdAt..expiresAt grows on every re-post and the display's
            // timeout pie (which reads it as the tile's lifetime) drifts low.
            createdAt: $now,
            expiresAt: $duration === null ? null : $now + $duration,
            apiKeyId: $apiKeyId,
        );
        $this->tiles->store($tile);
        $this->queue->remove($id); // was queued before, now placed

        return new TileUpsertResult($id, $tile, $existing === null, false);
    }

    /**
     * Move a placed tile to a new top-left cell (manual override of placement).
     * Tiles it lands on are evicted to the queue and re-placed by the drain.
     * Returns the moved tile, or null if no placed tile has that id.
     *
     * @throws TileLimitException when the target doesn't fit within the grid
     */
    public function move(string $id, int $x, int $y, int $now): ?Tile
    {
        $this->tiles->pruneExpired($now);

        $tile = $this->tiles->find($id);
        if ($tile === null) {
            return null;
        }

        $w = $tile->getPosition()->w;
        $h = $tile->getPosition()->h;
        if (!$this->placer->fitsInGrid($x, $y, $w, $h)) {
            throw TileLimitException::outOfBounds();
        }

        $target = new Position($x, $y, $w, $h);

        // Reserved spots (other than this tile's own) can't be overwritten.
        foreach ($this->reservations->all() as $reservedId => $reservedPos) {
            if ($reservedId !== $id && self::overlaps($reservedPos, $target)) {
                throw TileLimitException::reservedConflict();
            }
        }

        // Evicted tiles go to the FRONT of the queue (an `enqueuedAt` below any
        // existing entry), so a tile you bump off comes back before any backlog.
        $queued = $this->queue->all();
        $slot = ($queued === [] ? $now : $queued[0]->getEnqueuedAt()) - 1;

        // Evict any other live tiles overlapping the target to the queue.
        foreach ($this->tiles->findLive($now) as $other) {
            if ($other->getId() === $id || !self::overlaps($other->getPosition(), $target)) {
                continue;
            }

            $this->tiles->delete($other->getId());
            $expiresAt = $other->getExpiresAt();
            $this->queue->enqueue(new QueuedTile(
                id: $other->getId(),
                contentType: $other->getContentType(),
                content: $other->getContent(),
                width: $other->getPosition()->w,
                height: $other->getPosition()->h,
                duration: $expiresAt === null ? null : max(0, $expiresAt - $now),
                enqueuedAt: $slot--,
                apiKeyId: $other->getApiKeyId(),
            ));
        }

        $moved = new Tile(
            id: $id,
            contentType: $tile->getContentType(),
            content: $tile->getContent(),
            position: $target,
            createdAt: $tile->getCreatedAt(),
            expiresAt: $tile->getExpiresAt(),
            apiKeyId: $tile->getApiKeyId(), // move preserves authorship
        );
        $this->tiles->store($moved);

        // Moving a reserved tile re-pins it at the new spot.
        if ($this->reservations->has($id)) {
            $this->reservations->reserve($id, $target);
        }

        // Re-place evicted tiles into the remaining free space.
        $this->drainQueue($now);

        return $moved;
    }

    /**
     * Reserve the current position of a placed tile for its id (persists even
     * when the tile is gone, until released). Returns the reserved rectangle, or
     * null if no placed tile has that id.
     *
     * @throws TileLimitException when the reservation cap is reached
     */
    public function reserve(string $id): ?Position
    {
        $tile = $this->tiles->find($id);
        if ($tile === null) {
            return null;
        }

        if (!$this->reservations->has($id) && $this->reservations->count() >= $this->maxReservations) {
            throw TileLimitException::reservationsFull($this->maxReservations);
        }

        $this->reservations->reserve($id, $tile->getPosition());

        return $tile->getPosition();
    }

    /**
     * Release a reservation, then drain the queue into the freed space.
     */
    public function unreserve(string $id, int $now): void
    {
        $this->reservations->release($id);
        $this->drainQueue($now);
    }

    /**
     * @return array<string, Position> id => reserved rectangle
     */
    public function reservations(): array
    {
        return $this->reservations->all();
    }

    /**
     * Remove a tile (placed or queued), then drain the queue into any freed space.
     */
    public function delete(string $id, int $now): void
    {
        $this->tiles->delete($id);
        $this->queue->remove($id);
        $this->drainQueue($now);
    }

    /**
     * The live tiles for the display. Draining first means queued tiles appear
     * as soon as expiry frees space, on the display's normal poll.
     *
     * @return list<Tile>
     */
    public function liveTiles(int $now): array
    {
        $this->drainQueue($now);

        return $this->tiles->findLive($now);
    }

    /**
     * Place as many queued tiles as currently fit, in FIFO order. A queued tile
     * that still doesn't fit is left for a later round (greedy: smaller entries
     * behind it may still be placed).
     */
    private function drainQueue(int $now): void
    {
        $queued = $this->queue->all();
        if ($queued === []) {
            return;
        }

        $occupied = array_map(
            static fn (Tile $tile): Position => $tile->getPosition(),
            $this->tiles->findLive($now),
        );
        // Held (possibly empty) reserved spots are off-limits to queued tiles.
        foreach ($this->reservations->all() as $reservedPos) {
            $occupied[] = $reservedPos;
        }

        foreach ($queued as $entry) {
            try {
                $position = $this->placer->place($entry->getWidth(), $entry->getHeight(), $occupied);
            } catch (NoSpaceException) {
                continue; // doesn't fit now; leave it queued
            }

            $duration = $entry->getDuration();
            $this->tiles->store(new Tile(
                id: $entry->getId(),
                contentType: $entry->getContentType(),
                content: $entry->getContent(),
                position: $position,
                createdAt: $now,
                expiresAt: $duration === null ? null : $now + $duration,
                apiKeyId: $entry->getApiKeyId(),
            ));
            $this->queue->remove($entry->getId());
            $occupied[] = $position;
        }
    }

    /**
     * Resolve the requested footprint from either a named size or explicit
     * width/height (mutually exclusive).
     *
     * @return array{0: int, 1: int} [w, h]
     *
     * @throws TileLimitException on bad/conflicting/oversized dimensions
     */
    private function resolveFootprint(TileRequest $request): array
    {
        $size = $request->getSize();
        $width = $request->getWidth();
        $height = $request->getHeight();
        $hasCustom = $width !== null || $height !== null;

        if ($size !== null && $hasCustom) {
            throw TileLimitException::badRequest('Provide either "size" or "width"/"height", not both.');
        }
        if ($size !== null) {
            return [$size->width(), $size->height()];
        }
        if ($width === null || $height === null) {
            throw TileLimitException::badRequest('Provide a "size", or both "width" and "height".');
        }
        if ($width < 1 || $height < 1) {
            throw TileLimitException::badRequest('width and height must be at least 1.');
        }
        if ($width * $height > $this->maxTileArea) {
            throw TileLimitException::badRequest(\sprintf('width × height must not exceed %d cells.', $this->maxTileArea));
        }

        return [$width, $height];
    }

    /**
     * Whether two grid rectangles overlap.
     */
    private static function overlaps(Position $a, Position $b): bool
    {
        return $a->x < $b->x + $b->w && $a->x + $a->w > $b->x
            && $a->y < $b->y + $b->h && $a->y + $a->h > $b->y;
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
        // Reserved spots (other than this tile's own) are off-limits too.
        foreach ($this->reservations->all() as $reservedId => $reservedPos) {
            if ($reservedId !== $id) {
                $occupied[] = $reservedPos;
            }
        }

        return $occupied;
    }

    /**
     * Decide where to place a tile: reclaim a reservation if it matches, keep an
     * unchanged footprint's current spot, otherwise first-fit.
     *
     * @throws NoSpaceException when there's no room (transient) or it can't fit
     */
    private function resolvePosition(string $id, int $w, int $h, ?Tile $existing, int $now): Position
    {
        $reserved = $this->reservations->find($id);
        if ($reserved !== null) {
            if ($reserved->w === $w && $reserved->h === $h) {
                return $reserved; // reclaim the held spot
            }

            // Footprint changed: keep the top-left if the new size still fits and
            // is free; otherwise the reservation no longer applies — release it.
            $candidate = new Position($reserved->x, $reserved->y, $w, $h);
            if ($this->placer->fitsInGrid($candidate->x, $candidate->y, $w, $h)
                && $this->isFree($candidate, $this->occupiedExcept($id, $now))) {
                $this->reservations->reserve($id, $candidate);

                return $candidate;
            }

            $this->reservations->release($id);
        }

        // Keep the current position on re-post when the footprint is unchanged.
        if ($existing !== null
            && $existing->getPosition()->w === $w
            && $existing->getPosition()->h === $h) {
            return $existing->getPosition();
        }

        return $this->placer->place($w, $h, $this->occupiedExcept($id, $now));
    }

    /**
     * @param list<Position> $occupied
     */
    private function isFree(Position $candidate, array $occupied): bool
    {
        foreach ($occupied as $other) {
            if (self::overlaps($candidate, $other)) {
                return false;
            }
        }

        return true;
    }
}
