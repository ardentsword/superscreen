<?php

declare(strict_types=1);

namespace App\Service\Layout;

/**
 * Thrown when a tile request carries an unknown or missing content type.
 * The controller maps this to 422 Unprocessable Content.
 */
final class UnknownContentTypeException extends \RuntimeException
{
    public static function for(?string $type): self
    {
        return new self(\sprintf(
            'Unknown or missing content type: %s',
            $type === null || $type === '' ? '(none)' : $type,
        ));
    }
}
