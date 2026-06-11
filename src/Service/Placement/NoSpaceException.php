<?php

declare(strict_types=1);

namespace App\Service\Placement;

use App\Tile\Size;

/**
 * Thrown when a tile cannot be placed on the grid. The controller maps this to
 * a 409 Conflict. See docs/README.md §8 ("no room" policy).
 */
final class NoSpaceException extends \RuntimeException
{
    /** True when the tile can never fit (larger than the grid), vs. just no room right now. */
    public bool $permanent = false;

    public static function noRoom(Size $size, int $w, int $h, int $cols, int $rows): self
    {
        return new self(\sprintf(
            'No room for a %s (%d×%d) tile on the %d×%d grid.',
            $size->value, $w, $h, $cols, $rows,
        ));
    }

    public static function tooLarge(Size $size, int $w, int $h, int $cols, int $rows): self
    {
        $e = new self(\sprintf(
            'A %s (%d×%d) tile is larger than the %d×%d grid.',
            $size->value, $w, $h, $cols, $rows,
        ));
        $e->permanent = true;

        return $e;
    }
}
