<?php

declare(strict_types=1);

namespace App\Screen;

/**
 * A display screen: a named grid with its own dimensions. The tiles/queue/
 * reservations for a screen live in their own JSON store
 * (var/data/screens/<id>.json); this is just the metadata held in the screen
 * registry. See docs/MULTI-SCREEN.md.
 */
final readonly class Screen
{
    public function __construct(
        private string $id,
        private string $name,
        private int $cols,
        private int $rows,
        private int $gap,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCols(): int
    {
        return $this->cols;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function getGap(): int
    {
        return $this->gap;
    }
}
