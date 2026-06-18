<?php

declare(strict_types=1);

namespace App\Service\Screen;

use App\Service\SimpleDatabase\SimpleDataService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds (and memoises) a SimpleDataService bound to a screen's own JSON store,
 * var/data/screens/<id>.json. Also performs a one-time lazy migration of the
 * legacy single-screen state.json into the 'main' screen, so an existing install
 * keeps its layout with no manual step. See docs/MULTI-SCREEN.md §3/§7.
 */
final class ScreenStoreFactory
{
    /** @var array<string, SimpleDataService> */
    private array $cache = [];

    public function __construct(
        private readonly ScreenRegistry $registry,
        #[Autowire('%kernel.project_dir%/var/data')] private readonly string $dataDir,
    ) {}

    public function forScreen(string $id): SimpleDataService
    {
        $this->registry->validateId($id); // ensure the id is filename-safe

        if (!isset($this->cache[$id])) {
            $this->migrateLegacyMain($id);
            $this->cache[$id] = new SimpleDataService($this->screenFile($id));
        }

        return $this->cache[$id];
    }

    /**
     * Remove a screen's store file (used when a screen is deleted).
     */
    public function deleteStore(string $id): void
    {
        $this->registry->validateId($id);
        unset($this->cache[$id]);

        $file = $this->screenFile($id);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    private function screenFile(string $id): string
    {
        return $this->dataDir . '/screens/' . $id . '.json';
    }

    /**
     * Move a pre-multi-screen state.json into the 'main' screen store, once.
     */
    private function migrateLegacyMain(string $id): void
    {
        if ($id !== ScreenRegistry::DEFAULT_ID) {
            return;
        }

        $target = $this->screenFile($id);
        $legacy = $this->dataDir . '/state.json';
        if (file_exists($target) || !file_exists($legacy)) {
            return;
        }

        $dir = \dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        rename($legacy, $target);
    }
}
