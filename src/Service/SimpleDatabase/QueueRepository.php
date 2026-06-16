<?php

declare(strict_types=1);

namespace App\Service\SimpleDatabase;

use App\Dto\QueuedTile;
use App\Tile\ContentType;
use App\Tile\Size;

/**
 * The queue of tiles waiting for grid space, stored on the same JSON
 * SimpleDataService as tiles but keyed "queue.<id>". See docs/BACKEND.md.
 */
readonly class QueueRepository
{
    private const string KEY_PREFIX = 'queue.';

    public function __construct(
        private SimpleDataService $dataService,
    ) {}

    public function enqueue(QueuedTile $entry): void
    {
        $this->dataService->set($this->getKey($entry->getId()), $this->toArray($entry));
    }

    public function remove(string $id): void
    {
        $this->dataService->remove($this->getKey($id));
    }

    public function find(string $id): ?QueuedTile
    {
        $raw = $this->dataService->get($this->getKey($id));

        return $raw === null ? null : $this->fromArray($raw);
    }

    /**
     * Number of queued tiles (without deserializing them).
     */
    public function count(): int
    {
        $n = 0;
        foreach (array_keys($this->dataService->getAll()) as $key) {
            if (str_starts_with((string) $key, self::KEY_PREFIX)) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * All queued tiles in FIFO order (oldest enqueued first).
     *
     * @return list<QueuedTile>
     */
    public function all(): array
    {
        $entries = [];
        foreach ($this->dataService->getAll() as $key => $raw) {
            if (str_starts_with((string) $key, self::KEY_PREFIX)) {
                $entries[] = $this->fromArray($raw);
            }
        }

        usort($entries, static fn (QueuedTile $a, QueuedTile $b): int => $a->getEnqueuedAt() <=> $b->getEnqueuedAt());

        return $entries;
    }

    private function getKey(string $id): string
    {
        return self::KEY_PREFIX . $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(QueuedTile $entry): array
    {
        return [
            'id' => $entry->getId(),
            'content' => ['type' => $entry->getContentType()->value, ...$entry->getContent()],
            'w' => $entry->getWidth(),
            'h' => $entry->getHeight(),
            'duration' => $entry->getDuration(),
            'enqueued_at' => $entry->getEnqueuedAt(),
            'api_key_id' => $entry->getApiKeyId(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromArray(array $data): QueuedTile
    {
        $content = (array) $data['content'];
        $type = ContentType::from((string) ($content['type'] ?? ''));
        unset($content['type']);

        // Footprint is stored as w/h; fall back to a legacy `size` value.
        if (isset($data['w'], $data['h'])) {
            $w = (int) $data['w'];
            $h = (int) $data['h'];
        } else {
            $footprint = Size::from((string) $data['size'])->footprint();
            $w = $footprint['w'];
            $h = $footprint['h'];
        }

        return new QueuedTile(
            id: (string) $data['id'],
            contentType: $type,
            content: $content,
            width: $w,
            height: $h,
            duration: isset($data['duration']) ? (int) $data['duration'] : null,
            enqueuedAt: (int) $data['enqueued_at'],
            apiKeyId: isset($data['api_key_id']) ? (string) $data['api_key_id'] : null,
        );
    }
}
