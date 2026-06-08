<?php

declare(strict_types=1);

namespace App\Tile;

/**
 * A tile's content: a type plus its type-specific payload (e.g. `src` for
 * image/video/iframe, `text`, `html`). Serialized flat as
 * `{ "type": ..., <payload fields> }`.
 */
final readonly class Content
{
    /**
     * @param array<string, mixed> $data type-specific payload fields
     */
    public function __construct(
        public ContentType $type,
        public array $data = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, ...$this->data];
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $data = $raw;
        unset($data['type']);

        return new self(ContentType::from((string) ($raw['type'] ?? '')), $data);
    }
}
