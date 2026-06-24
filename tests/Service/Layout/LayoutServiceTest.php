<?php

declare(strict_types=1);

namespace App\Tests\Service\Layout;

use App\Dto\TileRequest;
use App\Service\Layout\LayoutService;
use App\Service\Layout\TileLimitException;
use App\Service\Layout\UnknownContentTypeException;
use App\Service\Placement\NoSpaceException;
use App\Service\Placement\TilePlacer;
use App\Service\SimpleDatabase\QueueRepository;
use App\Service\SimpleDatabase\ReservationRepository;
use App\Service\SimpleDatabase\SimpleDataService;
use App\Service\SimpleDatabase\TileRepository;
use App\Tile\Size;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutServiceTest extends TestCase
{
    private const int NOW = 1_000_000;

    private string $stateFile;
    private TileRepository $tiles;
    private QueueRepository $queue;
    private ReservationRepository $reservations;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/ss_test_' . bin2hex(random_bytes(6)) . '.json';
        // One shared store, like the single autowired service in production.
        $data = new SimpleDataService($this->stateFile);
        $this->tiles = new TileRepository($data);
        $this->queue = new QueueRepository($data);
        $this->reservations = new ReservationRepository($data);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateFile . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function service(
        int $cols = 6,
        int $rows = 4,
        int $maxQueue = 50,
        int $maxContentBytes = 262144,
        int $maxIdLength = 128,
        int $maxReservations = 30,
    ): LayoutService {
        return new LayoutService(
            $this->tiles,
            $this->queue,
            $this->reservations,
            new TilePlacer($cols, $rows),
            $maxQueue,
            $maxContentBytes,
            $maxIdLength,
            9,
            $maxReservations,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $id, Size $size, string $type = 'text', array $payload = ['text' => 'x'], ?int $duration = null): TileRequest
    {
        return new TileRequest(
            content: ['type' => $type, ...$payload],
            size: $size,
            id: $id,
            duration: $duration,
        );
    }

    #[Test]
    public function upsert_places_persists_and_reports_created(): void
    {
        $result = $this->service()->upsert($this->request('a', Size::Medium), self::NOW);

        self::assertTrue($result->created);
        self::assertSame('a', $result->tile->getId());
        self::assertSame(2, $result->tile->getPosition()->w);
        self::assertSame(1, $result->tile->getPosition()->h);
        // Actually persisted.
        self::assertNotNull($this->tiles->find('a'));
    }

    #[Test]
    public function upsert_computes_expiry_from_duration(): void
    {
        $service = $this->service();

        $permanent = $service->upsert($this->request('p', Size::Small, duration: null), self::NOW);
        self::assertNull($permanent->tile->getExpiresAt());

        $temporary = $service->upsert($this->request('t', Size::Small, duration: 60), self::NOW);
        self::assertSame(self::NOW + 60, $temporary->tile->getExpiresAt());
    }

    #[Test]
    public function re_upsert_keeps_position_restarts_lifetime_and_reports_updated(): void
    {
        $service = $this->service();
        $service->upsert($this->request('a', Size::Small, payload: ['text' => 'first']), self::NOW);
        // Occupy a later cell so a fresh placement would move 'a'.
        $service->upsert($this->request('b', Size::Small), self::NOW);

        $result = $service->upsert($this->request('a', Size::Small, payload: ['text' => 'second']), self::NOW + 500);

        self::assertFalse($result->created);
        self::assertEquals(0, $result->tile->getPosition()->x); // position preserved
        self::assertEquals(0, $result->tile->getPosition()->y);
        // The lifetime restarts on re-post: createdAt tracks the new write so the
        // window createdAt..expiresAt stays equal to the duration (the display's
        // timeout pie depends on this).
        self::assertSame(self::NOW + 500, $result->tile->getCreatedAt());
        self::assertSame('second', $this->tiles->find('a')->getContent()['text']);
    }

    #[Test]
    public function re_upsert_keeps_lifetime_window_equal_to_duration(): void
    {
        $service = $this->service();
        $service->upsert($this->request('a', Size::Small, duration: 120), self::NOW);

        // Re-post the same tile later with the same duration (a keep-alive refresh).
        $later = self::NOW + 80;
        $result = $service->upsert($this->request('a', Size::Small, duration: 120), $later);

        $tile = $result->tile;
        self::assertSame($later, $tile->getCreatedAt());
        self::assertSame($later + 120, $tile->getExpiresAt());
        // The window the pie reads as "lifetime" must equal the duration, not grow.
        self::assertSame(120, $tile->getExpiresAt() - $tile->getCreatedAt());
    }

    #[Test]
    public function generates_an_id_when_none_is_given(): void
    {
        $service = $this->service();

        $fromNull = $service->upsert(new TileRequest(['type' => 'text', 'text' => 'a'], Size::Small), self::NOW);
        $fromEmpty = $service->upsert(new TileRequest(content: ['type' => 'text', 'text' => 'b'], size: Size::Small, id: ''), self::NOW);

        foreach ([$fromNull, $fromEmpty] as $result) {
            self::assertTrue($result->created);
            self::assertNotSame('', $result->tile->getId());
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $result->tile->getId());
            self::assertNotNull($this->tiles->find($result->tile->getId()));
        }

        // Two generated ids must differ.
        self::assertNotSame($fromNull->tile->getId(), $fromEmpty->tile->getId());
    }

    #[Test]
    public function custom_dimensions_place_with_those_dimensions(): void
    {
        $result = $this->service()->upsert(
            new TileRequest(content: ['type' => 'text', 'text' => 'x'], width: 3, height: 2),
            self::NOW,
        );

        self::assertSame(3, $result->tile->getPosition()->w);
        self::assertSame(2, $result->tile->getPosition()->h);
    }

    #[Test]
    public function size_and_dimensions_together_is_rejected(): void
    {
        $this->expectException(TileLimitException::class);
        $this->service()->upsert(
            new TileRequest(content: ['type' => 'text', 'text' => 'x'], size: Size::Small, width: 2, height: 2),
            self::NOW,
        );
    }

    #[Test]
    public function dimensions_over_the_max_area_are_rejected(): void
    {
        try {
            $this->service()->upsert(
                new TileRequest(content: ['type' => 'text', 'text' => 'x'], width: 4, height: 3), // 12 > 9
                self::NOW,
            );
            self::fail('expected TileLimitException');
        } catch (TileLimitException $e) {
            self::assertSame(422, $e->statusCode);
        }
    }

    #[Test]
    public function neither_size_nor_dimensions_is_rejected(): void
    {
        $this->expectException(TileLimitException::class);
        $this->service()->upsert(
            new TileRequest(content: ['type' => 'text', 'text' => 'x']),
            self::NOW,
        );
    }

    #[Test]
    public function a_reserved_spot_is_held_for_its_id_across_delete_and_repost(): void
    {
        $service = $this->service();
        $service->upsert($this->request('a', Size::Medium), self::NOW); // (0,0) 2x1
        $reserved = $service->reserve('a');
        self::assertNotNull($reserved);

        // Delete the tile — the spot stays held; no other tile may take it.
        $service->delete('a', self::NOW);
        self::assertNull($this->tiles->find('a'));
        self::assertTrue($this->reservations->has('a'));

        // A different tile is placed elsewhere, not on the held (0,0).
        $service->upsert($this->request('b', Size::Medium), self::NOW);
        self::assertNotSame([0, 0], [$this->tiles->find('b')->getPosition()->x, $this->tiles->find('b')->getPosition()->y]);

        // Re-posting 'a' reclaims its reserved spot.
        $service->upsert($this->request('a', Size::Medium), self::NOW);
        self::assertSame([0, 0], [$this->tiles->find('a')->getPosition()->x, $this->tiles->find('a')->getPosition()->y]);
    }

    #[Test]
    public function a_reserved_tile_cannot_be_evicted_by_a_move(): void
    {
        $service = $this->service();
        $service->upsert($this->request('a', Size::Small), self::NOW); // (0,0)
        $service->upsert($this->request('b', Size::Small), self::NOW); // (1,0)
        $service->reserve('a');

        $this->expectException(TileLimitException::class);
        $service->move('b', 0, 0, self::NOW); // would land on reserved 'a'
    }

    #[Test]
    public function unreserve_frees_the_spot(): void
    {
        $service = $this->service(cols: 1, rows: 1);
        $service->upsert($this->request('a', Size::Small), self::NOW);
        $service->reserve('a');
        $service->delete('a', self::NOW); // gone, but spot still held

        // 'b' can't be placed while the only cell is reserved -> queued.
        $service->upsert($this->request('b', Size::Small), self::NOW);
        self::assertNull($this->tiles->find('b'));

        // Releasing the reservation drains 'b' into the freed cell.
        $service->unreserve('a', self::NOW);
        self::assertNotNull($this->tiles->find('b'));
    }

    #[Test]
    public function reserve_returns_null_for_an_unknown_tile(): void
    {
        self::assertNull($this->service()->reserve('nope'));
    }

    #[Test]
    public function reservation_cap_is_enforced(): void
    {
        $service = $this->service(maxReservations: 1);
        $service->upsert($this->request('a', Size::Small), self::NOW);
        $service->upsert($this->request('b', Size::Small), self::NOW);
        $service->reserve('a');

        $this->expectException(TileLimitException::class);
        $service->reserve('b');
    }

    #[Test]
    public function unknown_content_type_throws(): void
    {
        $this->expectException(UnknownContentTypeException::class);
        $this->service()->upsert($this->request('x', Size::Small, type: 'bogus'), self::NOW);
    }

    #[Test]
    public function no_space_queues_the_tile_instead_of_failing(): void
    {
        $service = $this->service(cols: 1, rows: 1);
        $service->upsert($this->request('a', Size::Small), self::NOW);

        $result = $service->upsert($this->request('b', Size::Small), self::NOW);

        self::assertTrue($result->queued);
        self::assertNull($result->tile);
        self::assertSame('b', $result->id);
        // Not on the grid yet, but waiting in the queue.
        self::assertNull($this->tiles->find('b'));
        self::assertNotNull($this->queue->find('b'));
    }

    #[Test]
    public function queued_tile_is_placed_when_a_tile_is_deleted(): void
    {
        $service = $this->service(cols: 1, rows: 1);
        $service->upsert($this->request('a', Size::Small), self::NOW);
        $service->upsert($this->request('b', Size::Small), self::NOW);

        $service->delete('a', self::NOW + 10);

        self::assertNotNull($this->tiles->find('b'));   // promoted from the queue
        self::assertNull($this->queue->find('b'));      // no longer queued
        self::assertSame(self::NOW + 10, $this->tiles->find('b')->getCreatedAt()); // TTL starts at placement
    }

    #[Test]
    public function queued_tile_is_placed_when_space_frees_via_expiry(): void
    {
        $service = $this->service(cols: 1, rows: 1);
        $service->upsert($this->request('a', Size::Small, duration: 60), self::NOW);
        $service->upsert($this->request('b', Size::Small), self::NOW);

        // 'a' has expired; liveTiles drains the queue into the freed cell.
        $live = $service->liveTiles(self::NOW + 120);

        $ids = array_map(static fn ($t) => $t->getId(), $live);
        self::assertSame(['b'], $ids);
        self::assertNull($this->queue->find('b'));
    }

    #[Test]
    public function id_longer_than_the_limit_is_rejected(): void
    {
        $service = $this->service(maxIdLength: 5);

        try {
            $service->upsert($this->request('way-too-long', Size::Small), self::NOW);
            self::fail('expected TileLimitException');
        } catch (TileLimitException $e) {
            self::assertSame(422, $e->statusCode);
        }
    }

    #[Test]
    public function content_larger_than_the_limit_is_rejected(): void
    {
        $service = $this->service(maxContentBytes: 50);

        try {
            $service->upsert($this->request('big', Size::Small, payload: ['text' => str_repeat('x', 200)]), self::NOW);
            self::fail('expected TileLimitException');
        } catch (TileLimitException $e) {
            self::assertSame(413, $e->statusCode);
        }
    }

    #[Test]
    public function queue_full_is_rejected(): void
    {
        $service = $this->service(cols: 1, rows: 1, maxQueue: 1);
        $service->upsert($this->request('a', Size::Small), self::NOW); // placed
        $service->upsert($this->request('b', Size::Small), self::NOW); // queued (1/1)

        try {
            $service->upsert($this->request('c', Size::Small), self::NOW);
            self::fail('expected TileLimitException');
        } catch (TileLimitException $e) {
            self::assertSame(503, $e->statusCode);
        }
        // The already-queued tile can still be updated despite a full queue.
        $service->upsert($this->request('b', Size::Small, payload: ['text' => 'updated']), self::NOW);
    }

    #[Test]
    public function expired_tiles_are_pruned_from_storage_on_write(): void
    {
        $service = $this->service();
        $service->upsert($this->request('temp', Size::Small, duration: 60), self::NOW);
        self::assertNotNull($this->tiles->find('temp'));

        // A later write prunes the now-expired tile out of storage entirely.
        $service->upsert($this->request('other', Size::Small), self::NOW + 120);

        self::assertNull($this->tiles->find('temp'));
    }

    #[Test]
    public function move_repositions_a_placed_tile(): void
    {
        $service = $this->service();
        $service->upsert($this->request('a', Size::Small), self::NOW); // placed at (0,0)

        $moved = $service->move('a', 3, 2, self::NOW);

        self::assertNotNull($moved);
        self::assertSame(3, $moved->getPosition()->x);
        self::assertSame(2, $moved->getPosition()->y);
        self::assertSame(3, $this->tiles->find('a')->getPosition()->x);
    }

    #[Test]
    public function move_onto_another_tile_evicts_it_to_the_queue_then_replaces(): void
    {
        $service = $this->service();
        $service->upsert($this->request('a', Size::Small), self::NOW); // (0,0)
        $service->upsert($this->request('b', Size::Small), self::NOW); // (1,0)

        $service->move('b', 0, 0, self::NOW); // drop b onto a

        $b = $this->tiles->find('b');
        self::assertSame([0, 0], [$b->getPosition()->x, $b->getPosition()->y]);
        // a was evicted and re-placed elsewhere (grid has room), not stuck queued.
        $a = $this->tiles->find('a');
        self::assertNotNull($a);
        self::assertNotSame([0, 0], [$a->getPosition()->x, $a->getPosition()->y]);
        self::assertNull($this->queue->find('a'));
    }

    #[Test]
    public function move_out_of_bounds_throws(): void
    {
        $service = $this->service(cols: 6, rows: 4);
        $service->upsert($this->request('big', Size::Large), self::NOW); // 2×2 at (0,0)

        try {
            $service->move('big', 5, 3, self::NOW); // 5+2 > 6 cols
            self::fail('expected TileLimitException');
        } catch (TileLimitException $e) {
            self::assertSame(422, $e->statusCode);
        }
    }

    #[Test]
    public function move_unknown_tile_returns_null(): void
    {
        self::assertNull($this->service()->move('nope', 0, 0, self::NOW));
    }

    #[Test]
    public function move_evicts_to_the_front_of_the_queue(): void
    {
        $service = $this->service(cols: 2, rows: 1); // two cells
        $service->upsert($this->request('a', Size::Small), self::NOW);       // (0,0)
        $service->upsert($this->request('b', Size::Small), self::NOW);       // (1,0)
        $service->upsert($this->request('backlog', Size::Small), self::NOW); // no room -> queued earlier
        self::assertNull($this->tiles->find('backlog'));

        // Drag b onto a: a is bumped; only b's old cell frees. The bumped 'a'
        // jumps ahead of the older 'backlog' and takes the freed cell.
        $service->move('b', 0, 0, self::NOW + 10);

        self::assertNotNull($this->tiles->find('a'));
        self::assertNull($this->tiles->find('backlog'));
        self::assertNotNull($this->queue->find('backlog'));
    }

    #[Test]
    public function upsert_stamps_the_api_key_and_it_survives_queue_eviction(): void
    {
        $service = $this->service(cols: 1, rows: 1);

        $placed = $service->upsert($this->request('a', Size::Small), self::NOW, 'key-A');
        self::assertSame('key-A', $placed->tile->getApiKeyId());
        self::assertSame('key-A', $this->tiles->find('a')->getApiKeyId());

        // 'b' can't fit -> queued, carrying its own key.
        $service->upsert($this->request('b', Size::Small), self::NOW, 'key-B');
        // Freeing 'a' drains 'b' in; its attribution is preserved.
        $service->delete('a', self::NOW);
        self::assertSame('key-B', $this->tiles->find('b')->getApiKeyId());
    }

    #[Test]
    public function tile_larger_than_the_grid_still_throws(): void
    {
        $service = $this->service(cols: 1, rows: 1);

        $this->expectException(NoSpaceException::class);
        $service->upsert($this->request('big', Size::Medium), self::NOW); // 2×1 cannot fit 1×1
    }
}
