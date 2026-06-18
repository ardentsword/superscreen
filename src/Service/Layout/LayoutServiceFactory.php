<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Screen\Screen;
use App\Service\Placement\TilePlacer;
use App\Service\Screen\ScreenStoreFactory;
use App\Service\SimpleDatabase\QueueRepository;
use App\Service\SimpleDatabase\ReservationRepository;
use App\Service\SimpleDatabase\TileRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a LayoutService bound to a specific screen: repositories over that
 * screen's own store, and a TilePlacer sized to the screen's grid. Resource
 * limits stay global. See docs/MULTI-SCREEN.md §4.
 */
final readonly class LayoutServiceFactory
{
    public function __construct(
        private ScreenStoreFactory $stores,
        #[Autowire('%app.limits.max_queue%')] private int $maxQueue = 50,
        #[Autowire('%app.limits.max_content_bytes%')] private int $maxContentBytes = 262144,
        #[Autowire('%app.limits.max_id_length%')] private int $maxIdLength = 128,
        #[Autowire('%app.limits.max_tile_area%')] private int $maxTileArea = 9,
        #[Autowire('%app.limits.max_reservations%')] private int $maxReservations = 30,
    ) {}

    public function forScreen(Screen $screen): LayoutService
    {
        $store = $this->stores->forScreen($screen->getId());

        return new LayoutService(
            new TileRepository($store),
            new QueueRepository($store),
            new ReservationRepository($store),
            new TilePlacer($screen->getCols(), $screen->getRows()),
            $this->maxQueue,
            $this->maxContentBytes,
            $this->maxIdLength,
            $this->maxTileArea,
            $this->maxReservations,
        );
    }
}
