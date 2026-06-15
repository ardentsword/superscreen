<?php

declare(strict_types=1);

namespace App\Service\Display;

use App\Dto\Tile;
use Twig\Environment;

/**
 * Renders a tile's content to HTML using a Twig template per content type
 * (templates/tile/<type>.html.twig). The display injects this HTML as-is, so all
 * content markup lives in one place server-side (and `text` is auto-escaped).
 */
final readonly class TileRenderer
{
    public function __construct(
        private Environment $twig,
    ) {}

    public function renderContent(Tile $tile): string
    {
        return trim($this->twig->render(
            \sprintf('tile/%s.html.twig', $tile->getContentType()->value),
            $tile->getContent(),
        ));
    }
}
