<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tile\Size;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only visual check of the tile sizes on a grid. Lays out one of each Size
 * preset using the real {@see Size} footprints, so the page reflects the actual
 * width/height values rather than hard-coded spans.
 */
final class GridPreviewController extends AbstractController
{
    private const int COLS = 6;
    private const int ROWS = 4;

    #[Route('/grid-preview', name: 'grid_preview', methods: ['GET'])]
    public function index(): Response
    {
        // Sample placements (size + top-left cell) that fully tile a 6×4 grid:
        // large/large/medium/medium across the top two rows, then a mix of
        // large, mediums and smalls across the bottom two.
        $placements = [
            [Size::Large, 0, 0],
            [Size::Large, 2, 0],
            [Size::Medium, 4, 0],
            [Size::Medium, 4, 1],
            [Size::Large, 0, 2],
            [Size::Medium, 2, 2],
            [Size::Medium, 2, 3],
            [Size::Small, 4, 2],
            [Size::Small, 5, 2],
            [Size::Small, 4, 3],
            [Size::Small, 5, 3],
        ];

        $tiles = array_map(
            static fn (array $p): array => [
                'size' => $p[0]->value,
                'x' => $p[1],
                'y' => $p[2],
                'w' => $p[0]->width(),
                'h' => $p[0]->height(),
            ],
            $placements,
        );

        return $this->render('preview/grid.html.twig', [
            'cols' => self::COLS,
            'rows' => self::ROWS,
            'gap' => 8,
            'tiles' => $tiles,
        ]);
    }
}
