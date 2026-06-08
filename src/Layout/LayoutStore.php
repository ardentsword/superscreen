<?php

declare(strict_types=1);

namespace App\Layout;

use App\Service\SimpleDatabase\SimpleDataService;
use App\Tile\Tile;

/**
 * Stores and retrieves tiles on top of the JSON SimpleDataService — the typed
 * repository for the layout. Tiles are keyed as "tile.<id>". Expiry (TTL) is
 * enforced on read, so callers only ever see live tiles. See docs/BACKEND.md.
 */
readonly class LayoutStore
{
    private const string KEY_PREFIX = 'tile.';

    public function __construct(
        private SimpleDataService $data,
    ) {}

    public function upsert(Tile $tile): void
    {
        $this->data->set(self::key($tile->id), $tile->toArray());
    }

    public function delete(string $id): void
    {
        $this->data->remove(self::key($id));
    }

    public function get(string $id): ?Tile
    {
        $raw = $this->data->get(self::key($id));

        return $raw === null ? null : Tile::fromArray($raw);
    }

    /**
     * Live (non-expired) tiles as of $now (unix seconds).
     *
     * @return list<Tile>
     */
    public function liveTiles(int $now): array
    {
        $live = [];
        foreach ($this->data->getAll() as $key => $raw) {
            if (!str_starts_with((string) $key, self::KEY_PREFIX)) {
                continue;
            }

            $tile = Tile::fromArray($raw);
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
        $tiles = array_map(static fn (Tile $t): array => $t->toArray(), $this->liveTiles($now));
        usort($tiles, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return hash('xxh128', json_encode($tiles, JSON_THROW_ON_ERROR));
    }

    private static function key(string $id): string
    {
        return self::KEY_PREFIX . $id;
    }
}
