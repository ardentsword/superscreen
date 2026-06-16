<?php

declare(strict_types=1);

namespace App\Service\Layout;

/**
 * Thrown when a write would exceed a resource limit (id/content size, or a full
 * queue). Carries the HTTP status the controller should return.
 */
final class TileLimitException extends \RuntimeException
{
    public int $statusCode = 422;

    public static function idTooLong(int $max): self
    {
        $e = new self(\sprintf('Tile id exceeds the maximum length of %d characters.', $max));
        $e->statusCode = 422;

        return $e;
    }

    public static function contentTooLarge(int $maxBytes): self
    {
        $e = new self(\sprintf('Tile content exceeds the maximum size of %d bytes.', $maxBytes));
        $e->statusCode = 413; // Payload Too Large

        return $e;
    }

    public static function queueFull(int $max): self
    {
        $e = new self(\sprintf('The tile queue is full (%d waiting); try again once space frees.', $max));
        $e->statusCode = 503; // Service Unavailable

        return $e;
    }

    public static function outOfBounds(): self
    {
        $e = new self('The target position does not fit within the grid.');
        $e->statusCode = 422;

        return $e;
    }
}
