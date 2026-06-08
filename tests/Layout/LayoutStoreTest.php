<?php

declare(strict_types=1);

namespace App\Tests\Layout;

use App\Layout\LayoutStore;
use App\Service\SimpleDatabase\SimpleDataService;
use App\Tile\Content;
use App\Tile\ContentType;
use App\Tile\Position;
use App\Tile\Size;
use App\Tile\Tile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutStoreTest extends TestCase
{
    private const int NOW = 1_000_000;

    private string $stateFile;
    private LayoutStore $store;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/ss_test_' . bin2hex(random_bytes(6)) . '.json';
        $this->store = new LayoutStore(new SimpleDataService($this->stateFile));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateFile . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    #[Test]
    public function empty_store_returns_no_tiles(): void
    {
        self::assertSame([], $this->store->liveTiles(self::NOW));
        self::assertNull($this->store->get('missing'));
    }

    #[Test]
    public function upsert_and_get_round_trip(): void
    {
        $this->store->upsert($this->tile('weather', Size::Medium, ContentType::Iframe, ['src' => 'https://example.com']));

        $tile = $this->store->get('weather');

        self::assertInstanceOf(Tile::class, $tile);
        self::assertSame('weather', $tile->id);
        self::assertSame(ContentType::Iframe, $tile->content->type);
        self::assertSame('https://example.com', $tile->content->data['src']);
        // medium = 2 x 1
        self::assertSame(2, $tile->position->w);
        self::assertSame(1, $tile->position->h);
        self::assertSame(self::NOW, $tile->createdAt);
        self::assertNull($tile->expiresAt);
    }

    #[Test]
    public function upsert_replaces_existing_tile_by_id(): void
    {
        $this->store->upsert($this->tile('a', Size::Small, ContentType::Text, ['text' => 'first']));
        $this->store->upsert($this->tile('a', Size::Small, ContentType::Text, ['text' => 'second']));

        self::assertCount(1, $this->store->liveTiles(self::NOW));
        self::assertSame('second', $this->store->get('a')->content->data['text']);
    }

    #[Test]
    public function live_tiles_filters_expired_but_keeps_permanent(): void
    {
        $this->store->upsert($this->tile('permanent', Size::Medium, ContentType::Text, ['text' => 'stay'], expiresAt: null));
        $this->store->upsert($this->tile('temporary', Size::Small, ContentType::Text, ['text' => 'go'], expiresAt: self::NOW + 60));

        self::assertCount(2, $this->store->liveTiles(self::NOW));
        self::assertCount(2, $this->store->liveTiles(self::NOW + 59));
        // At exactly expiry the tile is no longer live (expiresAt > now is false).
        self::assertCount(1, $this->store->liveTiles(self::NOW + 60));
        self::assertSame('permanent', $this->store->liveTiles(self::NOW + 120)[0]->id);
    }

    #[Test]
    public function delete_removes_a_tile(): void
    {
        $this->store->upsert($this->tile('a', Size::Small, ContentType::Text, ['text' => 'x']));
        $this->store->upsert($this->tile('b', Size::Small, ContentType::Text, ['text' => 'y']));

        $this->store->delete('a');

        self::assertNull($this->store->get('a'));
        self::assertCount(1, $this->store->liveTiles(self::NOW));
    }

    #[Test]
    public function delete_is_a_no_op_for_unknown_id(): void
    {
        $this->store->upsert($this->tile('a', Size::Small, ContentType::Text, ['text' => 'x']));

        $this->store->delete('does-not-exist');

        self::assertCount(1, $this->store->liveTiles(self::NOW));
    }

    #[Test]
    public function live_hash_is_stable_and_changes_on_write_and_expiry(): void
    {
        $this->store->upsert($this->tile('permanent', Size::Medium, ContentType::Text, ['text' => 'a'], expiresAt: null));
        $this->store->upsert($this->tile('temporary', Size::Small, ContentType::Text, ['text' => 'b'], expiresAt: self::NOW + 60));

        $hash = $this->store->liveHash(self::NOW);

        // Stable for the same state and time.
        self::assertSame($hash, $this->store->liveHash(self::NOW));
        // Changes purely from time-based expiry, with no write.
        self::assertNotSame($hash, $this->store->liveHash(self::NOW + 120));
        // Changes from a write.
        $this->store->upsert($this->tile('extra', Size::Small, ContentType::Text, ['text' => 'c']));
        self::assertNotSame($hash, $this->store->liveHash(self::NOW));
    }

    #[Test]
    public function hash_is_independent_of_insertion_order(): void
    {
        $this->store->upsert($this->tile('a', Size::Small, ContentType::Text, ['text' => 'a']));
        $this->store->upsert($this->tile('b', Size::Small, ContentType::Text, ['text' => 'b']));
        $hashAb = $this->store->liveHash(self::NOW);

        $other = new LayoutStore(new SimpleDataService($this->stateFile . '.alt'));
        $other->upsert($this->tile('b', Size::Small, ContentType::Text, ['text' => 'b']));
        $other->upsert($this->tile('a', Size::Small, ContentType::Text, ['text' => 'a']));
        $hashBa = $other->liveHash(self::NOW);
        foreach (glob($this->stateFile . '.alt*') ?: [] as $f) {
            @unlink($f);
        }

        self::assertSame($hashAb, $hashBa);
    }

    #[Test]
    public function state_is_persisted_as_valid_json_and_survives_a_new_store(): void
    {
        $this->store->upsert($this->tile('weather', Size::Large, ContentType::Image, ['src' => 'a.png']));

        self::assertFileExists($this->stateFile);
        self::assertIsArray(json_decode((string) file_get_contents($this->stateFile), true, flags: JSON_THROW_ON_ERROR));

        // A fresh store reading the same file sees the tile (no shared cache).
        $reopened = new LayoutStore(new SimpleDataService($this->stateFile));
        $tile = $reopened->get('weather');
        self::assertNotNull($tile);
        self::assertSame(2, $tile->position->w);
        self::assertSame(2, $tile->position->h);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tile(
        string $id,
        Size $size,
        ContentType $type,
        array $payload,
        ?int $expiresAt = null,
    ): Tile {
        return new Tile(
            id: $id,
            content: new Content($type, $payload),
            position: new Position(0, 0, $size->width(), $size->height()),
            createdAt: self::NOW,
            expiresAt: $expiresAt,
        );
    }
}
