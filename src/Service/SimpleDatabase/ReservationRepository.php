<?php

declare(strict_types=1);

namespace App\Service\SimpleDatabase;

use App\Tile\Position;

/**
 * Spot reservations: a tile id is bound to a fixed grid rectangle that's held
 * for it even when no tile currently occupies it. Stored on the shared JSON
 * store keyed "reserve.<id>". See docs/BACKEND.md.
 */
readonly class ReservationRepository
{
    private const string KEY_PREFIX = 'reserve.';

    public function __construct(
        private SimpleDataService $dataService,
    ) {}

    public function reserve(string $id, Position $position): void
    {
        $this->dataService->set($this->getKey($id), [
            'x' => $position->x,
            'y' => $position->y,
            'w' => $position->w,
            'h' => $position->h,
        ]);
    }

    public function release(string $id): bool
    {
        if ($this->dataService->get($this->getKey($id)) === null) {
            return false;
        }

        $this->dataService->remove($this->getKey($id));

        return true;
    }

    public function find(string $id): ?Position
    {
        $raw = $this->dataService->get($this->getKey($id));

        return $raw === null ? null : $this->toPosition($raw);
    }

    public function has(string $id): bool
    {
        return $this->dataService->get($this->getKey($id)) !== null;
    }

    /**
     * @return array<string, Position> id => reserved rectangle
     */
    public function all(): array
    {
        $reservations = [];
        foreach ($this->dataService->getAll() as $key => $raw) {
            if (str_starts_with((string) $key, self::KEY_PREFIX)) {
                $reservations[substr((string) $key, \strlen(self::KEY_PREFIX))] = $this->toPosition($raw);
            }
        }

        return $reservations;
    }

    public function count(): int
    {
        return \count($this->all());
    }

    private function getKey(string $id): string
    {
        return self::KEY_PREFIX . $id;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function toPosition(array $raw): Position
    {
        return new Position((int) $raw['x'], (int) $raw['y'], (int) $raw['w'], (int) $raw['h']);
    }
}
