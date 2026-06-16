<?php

declare(strict_types=1);

namespace App\Tile;

/**
 * The only sizing knob exposed by the write API. Each preset maps to a fixed
 * grid footprint (width × height in cells); the backend resolves the footprint
 * and assigns position. See docs/README.md §4.
 */
enum Size: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    /**
     * Footprint in grid cells.
     *
     * @return array{w: int, h: int}
     */
    public function footprint(): array
    {
        return match ($this) {
            self::Small => ['w' => 1, 'h' => 1],
            self::Medium => ['w' => 2, 'h' => 1],
            self::Large => ['w' => 2, 'h' => 2],
        };
    }

    public function width(): int
    {
        return $this->footprint()['w'];
    }

    public function height(): int
    {
        return $this->footprint()['h'];
    }

    /**
     * The Size whose footprint matches the given dimensions (the inverse of
     * footprint()). Used when re-queuing a placed tile that's been moved over.
     */
    public static function fromDimensions(int $w, int $h): self
    {
        foreach (self::cases() as $size) {
            if ($size->width() === $w && $size->height() === $h) {
                return $size;
            }
        }

        throw new \ValueError(\sprintf('No size matches footprint %d×%d.', $w, $h));
    }
}
