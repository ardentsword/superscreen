<?php

declare(strict_types=1);

namespace App\Tests\Service\SimpleDatabase;

use App\Dto\Tile;
use App\Service\SimpleDatabase\SimpleDataService;
use App\Service\SimpleDatabase\TileRepository;
use App\Tile\ContentType;
use App\Tile\Position;
use App\Tile\Size;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TileRepositoryTest extends TestCase
{
    private const int NOW = 1_000_000;

    private string $stateFile;
    private TileRepository $repository;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/ss_test_' . bin2hex(random_bytes(6)) . '.json';
        $this->repository = new TileRepository(new SimpleDataService($this->stateFile));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateFile . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    #[Test]
    public function empty_repository_returns_no_tiles(): void
    {
        self::assertSame([], $this->repository->findLive(self::NOW));
        self::assertNull($this->repository->find('missing'));
    }

    #[Test]
    public function store_and_find_round_trip(): void
    {
        $this->repository->store($this->tile('weather', Size::Medium, ContentType::Iframe, ['src' => 'https://example.com']));

        $tile = $this->repository->find('weather');

        self::assertInstanceOf(Tile::class, $tile);
        self::assertSame('weather', $tile->getId());
        self::assertSame(ContentType::Iframe, $tile->getContentType());
        self::assertSame('https://example.com', $tile->getContent()['src']);
        // medium = 2 x 1
        self::assertSame(2, $tile->getPosition()->w);
        self::assertSame(1, $tile->getPosition()->h);
        self::assertSame(self::NOW, $tile->getCreatedAt());
        self::assertNull($tile->getExpiresAt());
    }

    #[Test]
    public function store_replaces_existing_tile_by_id(): void
    {
        $this->repository->store($this->tile('a', Size::Small, ContentType::Text, ['text' => 'first']));
        $this->repository->store($this->tile('a', Size::Small, ContentType::Text, ['text' => 'second']));

        self::assertCount(1, $this->repository->findLive(self::NOW));
        self::assertSame('second', $this->repository->find('a')->getContent()['text']);
    }

    #[Test]
    public function find_live_filters_expired_but_keeps_permanent(): void
    {
        $this->repository->store($this->tile('permanent', Size::Medium, ContentType::Text, ['text' => 'stay'], expiresAt: null));
        $this->repository->store($this->tile('temporary', Size::Small, ContentType::Text, ['text' => 'go'], expiresAt: self::NOW + 60));

        self::assertCount(2, $this->repository->findLive(self::NOW));
        self::assertCount(2, $this->repository->findLive(self::NOW + 59));
        // At exactly expiry the tile is no longer live (expiresAt > now is false).
        self::assertCount(1, $this->repository->findLive(self::NOW + 60));
        self::assertSame('permanent', $this->repository->findLive(self::NOW + 120)[0]->getId());
    }

    #[Test]
    public function delete_removes_a_tile(): void
    {
        $this->repository->store($this->tile('a', Size::Small, ContentType::Text, ['text' => 'x']));
        $this->repository->store($this->tile('b', Size::Small, ContentType::Text, ['text' => 'y']));

        $this->repository->delete('a');

        self::assertNull($this->repository->find('a'));
        self::assertCount(1, $this->repository->findLive(self::NOW));
    }

    #[Test]
    public function delete_is_a_no_op_for_unknown_id(): void
    {
        $this->repository->store($this->tile('a', Size::Small, ContentType::Text, ['text' => 'x']));

        $this->repository->delete('does-not-exist');

        self::assertCount(1, $this->repository->findLive(self::NOW));
    }

    #[Test]
    public function live_hash_is_stable_and_changes_on_write_and_expiry(): void
    {
        $this->repository->store($this->tile('permanent', Size::Medium, ContentType::Text, ['text' => 'a'], expiresAt: null));
        $this->repository->store($this->tile('temporary', Size::Small, ContentType::Text, ['text' => 'b'], expiresAt: self::NOW + 60));

        $hash = $this->repository->liveHash(self::NOW);

        // Stable for the same state and time.
        self::assertSame($hash, $this->repository->liveHash(self::NOW));
        // Changes purely from time-based expiry, with no write.
        self::assertNotSame($hash, $this->repository->liveHash(self::NOW + 120));
        // Changes from a write.
        $this->repository->store($this->tile('extra', Size::Small, ContentType::Text, ['text' => 'c']));
        self::assertNotSame($hash, $this->repository->liveHash(self::NOW));
    }

    #[Test]
    public function hash_is_independent_of_insertion_order(): void
    {
        $this->repository->store($this->tile('a', Size::Small, ContentType::Text, ['text' => 'a']));
        $this->repository->store($this->tile('b', Size::Small, ContentType::Text, ['text' => 'b']));
        $hashAb = $this->repository->liveHash(self::NOW);

        $other = new TileRepository(new SimpleDataService($this->stateFile . '.alt'));
        $other->store($this->tile('b', Size::Small, ContentType::Text, ['text' => 'b']));
        $other->store($this->tile('a', Size::Small, ContentType::Text, ['text' => 'a']));
        $hashBa = $other->liveHash(self::NOW);
        foreach (glob($this->stateFile . '.alt*') ?: [] as $f) {
            @unlink($f);
        }

        self::assertSame($hashAb, $hashBa);
    }

    #[Test]
    public function state_is_persisted_as_valid_json_and_survives_a_new_repository(): void
    {
        $this->repository->store($this->tile('weather', Size::Large, ContentType::Image, ['src' => 'a.png']));

        self::assertFileExists($this->stateFile);
        self::assertIsArray(json_decode((string) file_get_contents($this->stateFile), true, flags: JSON_THROW_ON_ERROR));

        // A fresh repository reading the same file sees the tile (no shared cache).
        $reopened = new TileRepository(new SimpleDataService($this->stateFile));
        $tile = $reopened->find('weather');
        self::assertNotNull($tile);
        self::assertSame(2, $tile->getPosition()->w);
        self::assertSame(2, $tile->getPosition()->h);
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
            contentType: $type,
            content: $payload,
            position: new Position(0, 0, $size->width(), $size->height()),
            createdAt: self::NOW,
            expiresAt: $expiresAt,
        );
    }
}
