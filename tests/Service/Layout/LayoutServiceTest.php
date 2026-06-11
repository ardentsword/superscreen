<?php

declare(strict_types=1);

namespace App\Tests\Service\Layout;

use App\Dto\TileRequest;
use App\Service\Layout\LayoutService;
use App\Service\Layout\UnknownContentTypeException;
use App\Service\Placement\NoSpaceException;
use App\Service\Placement\TilePlacer;
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

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/ss_test_' . bin2hex(random_bytes(6)) . '.json';
        $this->tiles = new TileRepository(new SimpleDataService($this->stateFile));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateFile . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function service(int $cols = 6, int $rows = 4): LayoutService
    {
        return new LayoutService($this->tiles, new TilePlacer($cols, $rows));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $id, Size $size, string $type = 'text', array $payload = ['text' => 'x'], ?int $duration = null): TileRequest
    {
        return new TileRequest($id, ['type' => $type, ...$payload], $size, $duration);
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
    public function re_upsert_keeps_position_and_created_at_and_reports_updated(): void
    {
        $service = $this->service();
        $service->upsert($this->request('a', Size::Small, payload: ['text' => 'first']), self::NOW);
        // Occupy a later cell so a fresh placement would move 'a'.
        $service->upsert($this->request('b', Size::Small), self::NOW);

        $result = $service->upsert($this->request('a', Size::Small, payload: ['text' => 'second']), self::NOW + 500);

        self::assertFalse($result->created);
        self::assertEquals(0, $result->tile->getPosition()->x);
        self::assertEquals(0, $result->tile->getPosition()->y);
        self::assertSame(self::NOW, $result->tile->getCreatedAt()); // original createdAt preserved
        self::assertSame('second', $this->tiles->find('a')->getContent()['text']);
    }

    #[Test]
    public function unknown_content_type_throws(): void
    {
        $this->expectException(UnknownContentTypeException::class);
        $this->service()->upsert($this->request('x', Size::Small, type: 'bogus'), self::NOW);
    }

    #[Test]
    public function no_space_throws(): void
    {
        $service = $this->service(cols: 1, rows: 1);
        $service->upsert($this->request('a', Size::Small), self::NOW);

        $this->expectException(NoSpaceException::class);
        $service->upsert($this->request('b', Size::Small), self::NOW);
    }
}
