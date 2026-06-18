<?php

declare(strict_types=1);

namespace App\Tests\Service\Layout;

use App\Dto\Tile;
use App\Dto\TileRequest;
use App\Screen\Screen;
use App\Service\Layout\LayoutServiceFactory;
use App\Service\Placement\NoSpaceException;
use App\Service\Screen\ScreenRegistry;
use App\Service\Screen\ScreenStoreFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The factory wires a LayoutService to a screen's own store and grid, which is
 * what gives screens their isolation. See docs/MULTI-SCREEN.md §4.
 */
final class LayoutServiceFactoryTest extends TestCase
{
    private const int NOW = 1_000_000;

    private string $dir;
    private ScreenRegistry $registry;
    private LayoutServiceFactory $layouts;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ss_ms_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);

        $this->registry = new ScreenRegistry($this->dir . '/screens.json', 8, 5, 8, 20);
        $this->layouts = new LayoutServiceFactory(new ScreenStoreFactory($this->registry, $this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/screens/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/screens');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function request(string $id, ?int $w = null, ?int $h = null): TileRequest
    {
        return new TileRequest(
            content: ['type' => 'text', 'text' => 'x'],
            width: $w,
            height: $h,
            id: $id,
        );
    }

    /**
     * @param list<Tile> $tiles
     *
     * @return list<string>
     */
    private static function ids(array $tiles): array
    {
        return array_map(static fn (Tile $t): string => $t->getId(), $tiles);
    }

    #[Test]
    public function tiles_are_isolated_per_screen(): void
    {
        $a = $this->layouts->forScreen($this->registry->getOrCreate('alpha'));
        $b = $this->layouts->forScreen($this->registry->getOrCreate('beta'));

        $a->upsert($this->request('ta', 1, 1), self::NOW);
        $b->upsert($this->request('tb', 1, 1), self::NOW);

        self::assertSame(['ta'], self::ids($a->liveTiles(self::NOW)));
        self::assertSame(['tb'], self::ids($b->liveTiles(self::NOW)));

        // And on disk: a store file per screen.
        self::assertFileExists($this->dir . '/screens/alpha.json');
        self::assertFileExists($this->dir . '/screens/beta.json');
    }

    #[Test]
    public function placement_respects_each_screens_own_grid(): void
    {
        $this->registry->save(new Screen('small', 'small', 2, 2, 8));
        $this->registry->save(new Screen('big', 'big', 4, 4, 8));

        $big = $this->layouts->forScreen($this->registry->get('big'));
        $small = $this->layouts->forScreen($this->registry->get('small'));

        // A 3×3 tile fits the 4×4 screen...
        $result = $big->upsert($this->request('x', 3, 3), self::NOW);
        self::assertNotNull($result->tile);
        self::assertCount(1, $big->liveTiles(self::NOW));

        // ...but can never fit the 2×2 screen (larger than the grid).
        $this->expectException(NoSpaceException::class);
        $small->upsert($this->request('y', 3, 3), self::NOW);
    }

    #[Test]
    public function deleting_a_store_clears_a_screens_tiles(): void
    {
        $stores = new ScreenStoreFactory($this->registry, $this->dir);
        $layouts = new LayoutServiceFactory($stores);

        $service = $layouts->forScreen($this->registry->getOrCreate('temp'));
        $service->upsert($this->request('t1', 1, 1), self::NOW);
        self::assertFileExists($this->dir . '/screens/temp.json');

        $stores->deleteStore('temp');
        self::assertFileDoesNotExist($this->dir . '/screens/temp.json');
    }
}
