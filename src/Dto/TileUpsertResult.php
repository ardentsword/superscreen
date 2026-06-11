<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Result of an upsert: the stored tile and whether it was newly created (vs.
 * an update of an existing id). Lets the controller pick 201 vs 200.
 */
final readonly class TileUpsertResult
{
    public function __construct(
        public Tile $tile,
        public bool $created,
    ) {}
}
