<?php

declare(strict_types=1);

namespace App\Service\SimpleDatabase;

use App\Dto\Tile;
use App\Tile\ContentType;
use App\Tile\Position;

/**
 * Stores and retrieves tiles on top of the JSON SimpleDataService — the typed
 * repository for the layout. Tiles are keyed as "tile.<id>". Expiry (TTL) is
 * enforced on read, so callers only ever see live tiles. See docs/BACKEND.md.
 */
readonly class TileRepository
{
    private const string KEY_PREFIX = 'tile.';

    public function __construct(
        private SimpleDataService $dataService,
    ) {}

    public function store(Tile $tile): void
    {
        $this->dataService->set($this->getKey($tile->getId()), $this->toArray($tile));
    }

    public function delete(string $id): void
    {
        $this->dataService->remove($this->getKey($id));
    }

    public function find(string $id): ?Tile
    {
        $raw = $this->dataService->get($this->getKey($id));

        return $raw === null ? null : $this->fromArray($raw);
    }

    /**
     * Delete expired tiles from storage (TTL only filters on read, so without
     * this they accumulate forever). Returns the number removed.
     */
    public function pruneExpired(int $now): int
    {
        $expired = [];
        foreach ($this->dataService->getAll() as $key => $raw) {
            if (str_starts_with((string) $key, self::KEY_PREFIX) && !$this->fromArray($raw)->isLiveAt($now)) {
                $expired[] = (string) $key;
            }
        }

        $this->dataService->removeMany($expired);

        return \count($expired);
    }

    /**
     * Live (non-expired) tiles as of $now (unix seconds).
     *
     * @return list<Tile>
     */
    public function findLive(int $now): array
    {
        $live = [];
        foreach ($this->dataService->getAll() as $key => $raw) {
            if (!str_starts_with((string) $key, self::KEY_PREFIX)) {
                continue;
            }

            $tile = $this->fromArray($raw);
            if ($tile->isLiveAt($now)) {
                $live[] = $tile;
            }
        }

        return $live;
    }

    /**
     * Stable hash of the live layout — the basis for the layout ETag. It changes
     * on any write *and* on time-based expiry, so the display re-renders in both
     * cases. See docs/BACKEND.md §6.
     */
    public function liveHash(int $now): string
    {
        $tiles = array_map(fn (Tile $tile): array => $this->toArray($tile), $this->findLive($now));
        usort($tiles, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return hash('xxh128', json_encode($tiles, JSON_THROW_ON_ERROR));
    }

    private function getKey(string $id): string
    {
        return self::KEY_PREFIX . $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Tile $tile): array
    {
        $position = $tile->getPosition();

        return [
            'id' => $tile->getId(),
            'content' => ['type' => $tile->getContentType()->value, ...$tile->getContent()],
            'position' => ['x' => $position->x, 'y' => $position->y, 'w' => $position->w, 'h' => $position->h],
            'created_at' => $tile->getCreatedAt(),
            'expires_at' => $tile->getExpiresAt(),
            'api_key_id' => $tile->getApiKeyId(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromArray(array $data): Tile
    {
        $content = (array) $data['content'];
        $type = ContentType::from((string) ($content['type'] ?? ''));
        unset($content['type']);

        $position = (array) $data['position'];

        return new Tile(
            id: (string) $data['id'],
            contentType: $type,
            content: $content,
            position: new Position(
                (int) $position['x'],
                (int) $position['y'],
                (int) $position['w'],
                (int) $position['h'],
            ),
            createdAt: (int) $data['created_at'],
            expiresAt: isset($data['expires_at']) ? (int) $data['expires_at'] : null,
            apiKeyId: isset($data['api_key_id']) ? (string) $data['api_key_id'] : null,
        );
    }
}
