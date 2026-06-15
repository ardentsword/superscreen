<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Placement\NoSpaceException;
use App\Service\Placement\TilePlacer;
use App\Tile\Size;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only visual check of the tile sizes. It uses the same grid parameters and
 * the same {@see TilePlacer} as the real screen, so the preview always mirrors
 * the live grid (dimensions and placement) — no separate constants to drift.
 */
final class GridPreviewController extends AbstractController
{
    #[Route('/grid-preview', name: 'grid_preview', methods: ['GET'])]
    public function index(
        TilePlacer $placer,
        #[Autowire('%app.grid.cols%')] int $cols,
        #[Autowire('%app.grid.rows%')] int $rows,
        #[Autowire('%app.grid.gap%')] int $gap,
    ): Response {
        // Fill the grid with a repeating cycle of sizes, positioned by the real
        // placer, so the page reflects actual footprints and placement.
        $cycle = [Size::Large, Size::Medium, Size::Small];
        $occupied = [];
        $tiles = [];

        for ($i = 0, $misses = 0; $misses < \count($cycle); ++$i) {
            $size = $cycle[$i % \count($cycle)];

            try {
                $position = $placer->place($size, $occupied);
            } catch (NoSpaceException) {
                ++$misses; // this size no longer fits; stop once none do
                continue;
            }

            $misses = 0;
            $occupied[] = $position;
            $tiles[] = [
                'size' => $size->value,
                'x' => $position->x,
                'y' => $position->y,
                'w' => $position->w,
                'h' => $position->h,
            ];
        }

        return $this->render('preview/grid.html.twig', [
            'cols' => $cols,
            'rows' => $rows,
            'gap' => $gap,
            'tiles' => $tiles,
        ]);
    }
}
