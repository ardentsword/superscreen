<?php

declare(strict_types=1);

namespace App\Service\SimpleDatabase;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Minimal JSON-file key/value store: every key maps to an array. The whole
 * file is cached in memory and rewritten on each change.
 *
 * Adapted from the SimpleDatabase pattern used in other projects, with two
 * additions for this use case: remove(), and an atomic write (temp file +
 * rename) so a crash or power loss mid-write cannot corrupt the state — see
 * docs/BACKEND.md §4.
 */
class SimpleDataService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $dataCache = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/state.json')]
        private readonly string $dataFile,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function set(string $key, array $data): self
    {
        $cache = $this->getAll();
        $cache[$key] = $data;
        $this->dataCache = $cache;
        $this->writeCache();

        return $this;
    }

    public function remove(string $key): self
    {
        $cache = $this->getAll();
        if (!\array_key_exists($key, $cache)) {
            return $this;
        }

        unset($cache[$key]);
        $this->dataCache = $cache;
        $this->writeCache();

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->getAll()[$key] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAll(): array
    {
        if ($this->dataCache !== null) {
            return $this->dataCache;
        }

        if (!file_exists($this->dataFile)) {
            return $this->dataCache = [];
        }

        $raw = file_get_contents($this->dataFile);
        $decoded = ($raw === false || $raw === '')
            ? []
            : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $this->dataCache = \is_array($decoded) ? $decoded : [];
    }

    private function writeCache(): void
    {
        $dir = \dirname($this->dataFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $json = json_encode($this->dataCache ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Atomic write: write a temp file in the same directory, then rename
        // over the target (rename is atomic on the same filesystem).
        $tmp = $this->dataFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $this->dataFile);
    }
}
