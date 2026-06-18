<?php

declare(strict_types=1);

namespace App\Service\Screen;

/**
 * Thrown for invalid screen operations (bad id, bad grid, too many screens).
 * Carries the HTTP status the controller should return.
 */
final class ScreenException extends \RuntimeException
{
    public int $statusCode = 422;

    public static function invalidId(): self
    {
        $e = new self('Screen id must be 1–32 characters of lowercase letters, digits or hyphens, starting alphanumeric.');
        $e->statusCode = 422;

        return $e;
    }

    public static function badGrid(string $message): self
    {
        $e = new self($message);
        $e->statusCode = 422;

        return $e;
    }

    public static function tooMany(int $max): self
    {
        $e = new self(\sprintf('The maximum number of screens (%d) is reached.', $max));
        $e->statusCode = 409;

        return $e;
    }

    public static function protectedScreen(string $id): self
    {
        $e = new self(\sprintf('The "%s" screen cannot be deleted.', $id));
        $e->statusCode = 409;

        return $e;
    }
}
