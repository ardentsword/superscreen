<?php

declare(strict_types=1);

namespace App\Service\Screen;

use App\Screen\Screen;
use App\Service\SimpleDatabase\SimpleDataService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The registry of screens and their grid config, stored in its own JSON file
 * (var/data/screens.json), keyed "screen.<id>". Owns screen-id validation — the
 * id becomes part of a filename, so this is what guards against path traversal —
 * and the screen-count cap. See docs/MULTI-SCREEN.md §4/§8.
 */
final readonly class ScreenRegistry
{
    /** The always-present default screen (the pre-multi-screen layout). */
    public const string DEFAULT_ID = 'main';

    private const string KEY_PREFIX = 'screen.';
    // The id ends up in a filename, so keep it a strict slug.
    private const string ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    private SimpleDataService $store;

    public function __construct(
        // Its own JSON file, separate from per-screen tile state and from keys.json.
        #[Autowire('%kernel.project_dir%/var/data/screens.json')] string $dataFile,
        #[Autowire('%app.grid.cols%')] private int $defaultCols,
        #[Autowire('%app.grid.rows%')] private int $defaultRows,
        #[Autowire('%app.grid.gap%')] private int $defaultGap,
        #[Autowire('%app.limits.max_screens%')] private int $maxScreens = 20,
    ) {
        $this->store = new SimpleDataService($dataFile);
    }

    /**
     * @throws ScreenException when the id is not a valid slug
     */
    public function validateId(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw ScreenException::invalidId();
        }
    }

    public function has(string $id): bool
    {
        return $this->store->get(self::KEY_PREFIX . $id) !== null;
    }

    public function get(string $id): ?Screen
    {
        $raw = $this->store->get(self::KEY_PREFIX . $id);

        return $raw === null ? null : self::fromArray($id, $raw);
    }

    /**
     * @return list<Screen>
     */
    public function all(): array
    {
        $screens = [];
        foreach ($this->store->getAll() as $key => $raw) {
            if (str_starts_with((string) $key, self::KEY_PREFIX)) {
                $screens[] = self::fromArray(substr((string) $key, \strlen(self::KEY_PREFIX)), $raw);
            }
        }
        usort($screens, static fn (Screen $a, Screen $b): int => strcmp($a->getId(), $b->getId()));

        return $screens;
    }

    /**
     * Get a screen, creating it with the default grid config if it doesn't exist.
     *
     * @throws ScreenException on a bad id or when the screen cap is reached
     */
    public function getOrCreate(string $id): Screen
    {
        $this->validateId($id);

        $existing = $this->get($id);
        if ($existing !== null) {
            return $existing;
        }

        if ($this->count() >= $this->maxScreens) {
            throw ScreenException::tooMany($this->maxScreens);
        }

        $screen = new Screen($id, $id, $this->defaultCols, $this->defaultRows, $this->defaultGap);
        $this->save($screen);

        return $screen;
    }

    /**
     * @throws ScreenException on a bad id
     */
    public function save(Screen $screen): void
    {
        $this->validateId($screen->getId());
        $this->store->set(self::KEY_PREFIX . $screen->getId(), [
            'name' => $screen->getName(),
            'cols' => $screen->getCols(),
            'rows' => $screen->getRows(),
            'gap' => $screen->getGap(),
        ]);
    }

    public function delete(string $id): void
    {
        $this->store->remove(self::KEY_PREFIX . $id);
    }

    public function count(): int
    {
        $n = 0;
        foreach (array_keys($this->store->getAll()) as $key) {
            if (str_starts_with((string) $key, self::KEY_PREFIX)) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function fromArray(string $id, array $raw): Screen
    {
        return new Screen(
            $id,
            (string) ($raw['name'] ?? $id),
            (int) ($raw['cols'] ?? 0),
            (int) ($raw['rows'] ?? 0),
            (int) ($raw['gap'] ?? 8),
        );
    }
}
