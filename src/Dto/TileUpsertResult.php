<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Result of an upsert. Either the tile was placed (`tile` set, `created` tells
 * new vs. updated) or there was no room and it was queued (`queued` true,
 * `tile` null). `id` is always set (generated if the caller omitted it).
 */
final readonly class TileUpsertResult
{
    public function __construct(
        public string $id,
        public ?Tile $tile,
        public bool $created,
        public bool $queued,
    ) {}
}
