<?php

declare(strict_types=1);

namespace App\Tile;

/**
 * The kinds of content a tile can hold. See docs/README.md §4 (content types).
 */
enum ContentType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Iframe = 'iframe';
    case Html = 'html';
}
