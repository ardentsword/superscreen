<?php

declare(strict_types=1);

namespace App\Tests\Service\Screen;

use App\Screen\Screen;
use App\Service\Screen\ScreenException;
use App\Service\Screen\ScreenRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenRegistryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/ss_screens_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->file . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function registry(int $maxScreens = 20): ScreenRegistry
    {
        return new ScreenRegistry($this->file, 8, 5, 8, $maxScreens);
    }

    #[Test]
    public function get_returns_null_for_an_unknown_screen(): void
    {
        self::assertNull($this->registry()->get('lobby'));
        self::assertFalse($this->registry()->has('lobby'));
    }

    #[Test]
    public function get_or_create_seeds_default_grid_and_persists(): void
    {
        $registry = $this->registry();
        $screen = $registry->getOrCreate('lobby');

        self::assertSame('lobby', $screen->getId());
        self::assertSame('lobby', $screen->getName());
        self::assertSame(8, $screen->getCols());
        self::assertSame(5, $screen->getRows());
        self::assertSame(8, $screen->getGap());

        // A fresh registry over the same file sees it.
        self::assertTrue($this->registry()->has('lobby'));
    }

    #[Test]
    public function get_or_create_returns_the_existing_screen_unchanged(): void
    {
        $registry = $this->registry();
        $registry->save(new Screen('lobby', 'Lobby', 4, 6, 12));

        $screen = $registry->getOrCreate('lobby');
        self::assertSame('Lobby', $screen->getName());
        self::assertSame(4, $screen->getCols());
        self::assertSame(6, $screen->getRows());
        self::assertSame(12, $screen->getGap());
    }

    #[Test]
    public function save_then_get_round_trips(): void
    {
        $registry = $this->registry();
        $registry->save(new Screen('kitchen', 'Kitchen', 3, 7, 10));

        $screen = $registry->get('kitchen');
        self::assertNotNull($screen);
        self::assertSame('Kitchen', $screen->getName());
        self::assertSame(3, $screen->getCols());
        self::assertSame(7, $screen->getRows());
        self::assertSame(10, $screen->getGap());
    }

    #[Test]
    public function all_lists_screens_sorted_by_id(): void
    {
        $registry = $this->registry();
        $registry->getOrCreate('main');
        $registry->getOrCreate('lobby');
        $registry->getOrCreate('kitchen');

        self::assertSame(
            ['kitchen', 'lobby', 'main'],
            array_map(static fn (Screen $s): string => $s->getId(), $registry->all()),
        );
    }

    #[Test]
    public function delete_removes_a_screen(): void
    {
        $registry = $this->registry();
        $registry->getOrCreate('lobby');
        self::assertTrue($registry->has('lobby'));

        $registry->delete('lobby');
        self::assertFalse($registry->has('lobby'));
    }

    #[Test]
    public function get_or_create_enforces_the_screen_cap(): void
    {
        $registry = $this->registry(maxScreens: 2);
        $registry->getOrCreate('a');
        $registry->getOrCreate('b');

        $this->expectException(ScreenException::class);
        $registry->getOrCreate('c');
    }

    #[Test]
    public function an_existing_screen_is_returned_even_at_the_cap(): void
    {
        $registry = $this->registry(maxScreens: 1);
        $registry->getOrCreate('a');

        // Not a new screen, so the cap doesn't apply.
        self::assertSame('a', $registry->getOrCreate('a')->getId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../etc'];
        yield 'slash' => ['a/b'];
        yield 'dot' => ['a.b'];
        yield 'uppercase' => ['Lobby'];
        yield 'leading hyphen' => ['-lobby'];
        yield 'too long' => [str_repeat('a', 33)];
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function get_or_create_rejects_invalid_ids(string $id): void
    {
        $this->expectException(ScreenException::class);
        $this->registry()->getOrCreate($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validIds(): iterable
    {
        yield 'simple' => ['lobby'];
        yield 'digits' => ['screen1'];
        yield 'hyphenated' => ['big-lobby-2'];
        yield 'single char' => ['a'];
        yield 'max length' => [str_repeat('a', 32)];
    }

    #[Test]
    #[DataProvider('validIds')]
    public function valid_ids_are_accepted(string $id): void
    {
        self::assertSame($id, $this->registry()->getOrCreate($id)->getId());
    }
}
