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
use App\Service\SimpleDatabase\TileRepository;
use App\Tile\ContentType;
use App\Tile\Position;
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
        private TilePlacer $placer,
        #[Autowire('%app.limits.max_queue%')] private int $maxQueue = 50,
        #[Autowire('%app.limits.max_content_bytes%')] private int $maxContentBytes = 262144,
        #[Autowire('%app.limits.max_id_length%')] private int $maxIdLength = 128,
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

        // Bound resource use (the API is a public write surface).
        if (mb_strlen($id) > $this->maxIdLength) {
            throw TileLimitException::idTooLong($this->maxIdLength);
        }
        if (\strlen((string) json_encode($content)) > $this->maxContentBytes) {
            throw TileLimitException::contentTooLarge($this->maxContentBytes);
        }

        // Drop expired tiles so storage can't grow unbounded over time.
        $this->tiles->pruneExpired($now);

        $existing = $this->tiles->find($id);
        $size = $request->getSize();

        try {
            // Keep the current position on re-post when the footprint is
            // unchanged, so the screen doesn't reshuffle; otherwise place fresh.
            $position = ($existing !== null
                && $existing->getPosition()->w === $size->width()
                && $existing->getPosition()->h === $size->height())
                ? $existing->getPosition()
                : $this->placer->place($size, $this->occupiedExcept($id, $now));
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
                size: $size,
                duration: $request->getDuration(),
                enqueuedAt: $this->queue->find($id)?->getEnqueuedAt() ?? $now,
            ));

            return new TileUpsertResult($id, null, false, true);
        }

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
        $this->queue->remove($id); // was queued before, now placed

        return new TileUpsertResult($id, $tile, $existing === null, false);
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

        foreach ($queued as $entry) {
            try {
                $position = $this->placer->place($entry->getSize(), $occupied);
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
            ));
            $this->queue->remove($entry->getId());
            $occupied[] = $position;
        }
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
