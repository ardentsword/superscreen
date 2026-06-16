<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Body of PATCH /api/tiles/{id}/position — the target top-left grid cell for a
 * manual move. The footprint (w/h) is kept from the existing tile.
 */
readonly class MoveRequest
{
    public function __construct(
        private int $x,
        private int $y,
    ) {}

    public function getX(): int
    {
        return $this->x;
    }

    public function getY(): int
    {
        return $this->y;
    }
}
