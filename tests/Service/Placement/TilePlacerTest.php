<?php

declare(strict_types=1);

namespace App\Tests\Service\Placement;

use App\Service\Placement\NoSpaceException;
use App\Service\Placement\TilePlacer;
use App\Tile\Position;
use App\Tile\Size;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TilePlacerTest extends TestCase
{
    #[Test]
    public function places_each_size_top_left_on_an_empty_grid(): void
    {
        $placer = new TilePlacer(cols: 6, rows: 4);

        self::assertEquals(new Position(0, 0, 1, 1), $placer->place(Size::Small, []));
        self::assertEquals(new Position(0, 0, 2, 1), $placer->place(Size::Medium, []));
        self::assertEquals(new Position(0, 0, 2, 2), $placer->place(Size::Large, []));
    }

    #[Test]
    public function places_an_extra_large_tile(): void
    {
        $placer = new TilePlacer(cols: 8, rows: 5);

        self::assertEquals(new Position(0, 0, 3, 3), $placer->place(Size::ExtraLarge, []));
        self::assertSame(Size::ExtraLarge, Size::fromDimensions(3, 3));
    }

    #[Test]
    public function first_fit_skips_occupied_cells_left_to_right(): void
    {
        $placer = new TilePlacer(cols: 6, rows: 4);

        $position = $placer->place(Size::Small, [new Position(0, 0, 1, 1)]);

        self::assertEquals(new Position(1, 0, 1, 1), $position);
    }

    #[Test]
    public function large_tile_skips_a_spot_that_would_overlap(): void
    {
        $placer = new TilePlacer(cols: 6, rows: 4);

        // A single cell taken at (1,0) blocks a 2×2 at x=0 and x=1; first fit is x=2.
        $position = $placer->place(Size::Large, [new Position(1, 0, 1, 1)]);

        self::assertEquals(new Position(2, 0, 2, 2), $position);
    }

    #[Test]
    public function wraps_to_the_next_row_when_a_row_is_full(): void
    {
        $placer = new TilePlacer(cols: 2, rows: 2);

        $first = $placer->place(Size::Medium, []);
        self::assertEquals(new Position(0, 0, 2, 1), $first);

        $second = $placer->place(Size::Medium, [$first]);
        self::assertEquals(new Position(0, 1, 2, 1), $second);
    }

    #[Test]
    public function throws_when_the_grid_is_full(): void
    {
        $placer = new TilePlacer(cols: 2, rows: 2);
        $occupied = [new Position(0, 0, 2, 2)];

        $this->expectException(NoSpaceException::class);
        $placer->place(Size::Small, $occupied);
    }

    #[Test]
    public function throws_when_the_tile_is_larger_than_the_grid(): void
    {
        $placer = new TilePlacer(cols: 1, rows: 1);

        $this->expectException(NoSpaceException::class);
        $placer->place(Size::Medium, []);
    }

    #[Test]
    public function ignores_out_of_bounds_occupancy(): void
    {
        $placer = new TilePlacer(cols: 2, rows: 2);

        // A stale position outside the grid must not block placement.
        $position = $placer->place(Size::Small, [new Position(5, 5, 1, 1)]);

        self::assertEquals(new Position(0, 0, 1, 1), $position);
    }
}
