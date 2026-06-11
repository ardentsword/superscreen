<?php

declare(strict_types=1);

namespace App\Service\Placement;

use App\Tile\Position;
use App\Tile\Size;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assigns a grid position to a tile of a given size using a first-fit scan
 * (top-to-bottom, then left-to-right) over the free cells. Throws when the grid
 * has no room. See docs/README.md §8 (placement strategy / "no room" policy).
 */
final readonly class TilePlacer
{
    public function __construct(
        #[Autowire('%app.grid.cols%')] private int $cols,
        #[Autowire('%app.grid.rows%')] private int $rows,
    ) {}

    /**
     * @param list<Position> $occupied positions already taken on the grid
     *
     * @throws NoSpaceException when the tile does not fit anywhere
     */
    public function place(Size $size, array $occupied): Position
    {
        $w = $size->width();
        $h = $size->height();

        if ($w > $this->cols || $h > $this->rows) {
            throw NoSpaceException::tooLarge($size, $w, $h, $this->cols, $this->rows);
        }

        $taken = $this->buildOccupancy($occupied);

        for ($y = 0; $y + $h <= $this->rows; ++$y) {
            for ($x = 0; $x + $w <= $this->cols; ++$x) {
                if ($this->isFree($taken, $x, $y, $w, $h)) {
                    return new Position($x, $y, $w, $h);
                }
            }
        }

        throw NoSpaceException::noRoom($size, $w, $h, $this->cols, $this->rows);
    }

    /**
     * @param list<Position> $occupied
     *
     * @return array<int, array<int, bool>> taken[y][x]
     */
    private function buildOccupancy(array $occupied): array
    {
        $taken = [];
        foreach ($occupied as $position) {
            for ($y = $position->y; $y < $position->y + $position->h; ++$y) {
                for ($x = $position->x; $x < $position->x + $position->w; ++$x) {
                    if ($x >= 0 && $x < $this->cols && $y >= 0 && $y < $this->rows) {
                        $taken[$y][$x] = true;
                    }
                }
            }
        }

        return $taken;
    }

    /**
     * @param array<int, array<int, bool>> $taken
     */
    private function isFree(array $taken, int $x, int $y, int $w, int $h): bool
    {
        for ($yy = $y; $yy < $y + $h; ++$yy) {
            for ($xx = $x; $xx < $x + $w; ++$xx) {
                if (!empty($taken[$yy][$xx])) {
                    return false;
                }
            }
        }

        return true;
    }
}
