<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MoveRequest;
use App\Dto\Tile;
use App\Dto\TileRequest;
use App\EventSubscriber\ApiKeySubscriber;
use App\Screen\Screen;
use App\Service\Display\TileRenderer;
use App\Service\Layout\LayoutServiceFactory;
use App\Service\Layout\TileLimitException;
use App\Service\Layout\UnknownContentTypeException;
use App\Service\Placement\NoSpaceException;
use App\Service\Screen\ScreenException;
use App\Service\Screen\ScreenRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The write/read API for a screen's layout. Each action has two routes: an
 * unscoped one ("/api/tiles", … — defaults to the "main" screen, so existing
 * callers keep working) and a screen-scoped one ("/api/screens/{screen}/tiles", …).
 * See docs/BACKEND.md §3 and docs/MULTI-SCREEN.md §5.
 */
#[Route('/api', name: 'api_')]
final class TileApiController extends AbstractController
{
    public function __construct(
        private readonly ScreenRegistry $screens,
        private readonly LayoutServiceFactory $layoutFactory,
    ) {}

    /**
     * Add or replace a tile (upsert by id). Writing to an unknown screen creates
     * it with the default grid.
     */
    #[Route('/tiles', name: 'tiles_upsert', defaults: ['screen' => ScreenRegistry::DEFAULT_ID], methods: ['POST'])]
    #[Route('/screens/{screen}/tiles', name: 'screen_tiles_upsert', methods: ['POST'])]
    public function upsert(
        string $screen,
        Request $httpRequest,
        #[MapRequestPayload] TileRequest $request,
    ): JsonResponse {
        $apiKeyId = $httpRequest->attributes->get(ApiKeySubscriber::ATTRIBUTE);

        try {
            $layout = $this->layoutFactory->forScreen($this->screens->getOrCreate($screen));
            $result = $layout->upsert($request, time(), \is_string($apiKeyId) ? $apiKeyId : null);
        } catch (ScreenException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        } catch (UnknownContentTypeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TileLimitException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        } catch (NoSpaceException $e) {
            // Only thrown when the tile can never fit (larger than the grid).
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        // No room right now: the tile was queued and will be placed when space frees.
        if ($result->queued) {
            return $this->json(['id' => $result->id, 'status' => 'queued'], Response::HTTP_ACCEPTED);
        }

        $position = $result->tile->getPosition();

        return $this->json([
            'id' => $result->id,
            'position' => [
                'x' => $position->x,
                'y' => $position->y,
                'w' => $position->w,
                'h' => $position->h,
            ],
            'expires_at' => $result->tile->getExpiresAt(),
        ], $result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Move a placed tile to a new top-left cell. Tiles it lands on are evicted
     * to the queue. Returns the resolved position.
     */
    #[Route('/tiles/{id}/position', name: 'tiles_move', defaults: ['screen' => ScreenRegistry::DEFAULT_ID], methods: ['PATCH'])]
    #[Route('/screens/{screen}/tiles/{id}/position', name: 'screen_tiles_move', methods: ['PATCH'])]
    public function move(
        string $screen,
        string $id,
        #[MapRequestPayload] MoveRequest $move,
    ): JsonResponse {
        try {
            $layout = $this->layoutFactory->forScreen($this->screens->getOrCreate($screen));
            $tile = $layout->move($id, $move->getX(), $move->getY(), time());
        } catch (ScreenException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        } catch (TileLimitException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        }

        if ($tile === null) {
            return $this->json(['error' => 'Tile not found.'], Response::HTTP_NOT_FOUND);
        }

        $position = $tile->getPosition();

        return $this->json([
            'id' => $id,
            'position' => [
                'x' => $position->x,
                'y' => $position->y,
                'w' => $position->w,
                'h' => $position->h,
            ],
        ]);
    }

    /**
     * Reserve (pin) a placed tile's current spot for its id. The spot is held
     * even when the tile is gone, until released.
     */
    #[Route('/tiles/{id}/reservation', name: 'tiles_reserve', defaults: ['screen' => ScreenRegistry::DEFAULT_ID], methods: ['PUT'])]
    #[Route('/screens/{screen}/tiles/{id}/reservation', name: 'screen_tiles_reserve', methods: ['PUT'])]
    public function reserve(string $screen, string $id): JsonResponse
    {
        try {
            $layout = $this->layoutFactory->forScreen($this->screens->getOrCreate($screen));
            $position = $layout->reserve($id);
        } catch (ScreenException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        } catch (TileLimitException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        }

        if ($position === null) {
            return $this->json(['error' => 'Tile not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $id,
            'reserved' => true,
            'position' => ['x' => $position->x, 'y' => $position->y, 'w' => $position->w, 'h' => $position->h],
        ]);
    }

    /**
     * Release a reservation (un-pin). Idempotent.
     */
    #[Route('/tiles/{id}/reservation', name: 'tiles_unreserve', defaults: ['screen' => ScreenRegistry::DEFAULT_ID], methods: ['DELETE'])]
    #[Route('/screens/{screen}/tiles/{id}/reservation', name: 'screen_tiles_unreserve', methods: ['DELETE'])]
    public function unreserve(string $screen, string $id): JsonResponse
    {
        try {
            $layout = $this->layoutFactory->forScreen($this->screens->getOrCreate($screen));
        } catch (ScreenException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        }

        $layout->unreserve($id, time());

        return $this->json(['id' => $id, 'reserved' => false]);
    }

    /**
     * Remove a tile by id (placed or queued). Idempotent. Frees space, so the
     * queue is drained afterwards. A reservation for the id is kept (persistent).
     */
    #[Route('/tiles/{id}', name: 'tiles_delete', defaults: ['screen' => ScreenRegistry::DEFAULT_ID], methods: ['DELETE'])]
    #[Route('/screens/{screen}/tiles/{id}', name: 'screen_tiles_delete', methods: ['DELETE'])]
    public function delete(string $screen, string $id): JsonResponse
    {
        try {
            $layout = $this->layoutFactory->forScreen($this->screens->getOrCreate($screen));
        } catch (ScreenException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        }

        $layout->delete($id, time());

        return $this->json(['deleted' => $id]);
    }

    /**
     * The single layout snapshot the display polls: grid + live tiles, with an
     * ETag so unchanged polls return 304. The "main" screen always exists; any
     * other unknown screen is a 404. See docs/BACKEND.md §6.
     */
    #[Route('/layout', name: 'layout', defaults: ['screen' => ScreenRegistry::DEFAULT_ID], methods: ['GET'])]
    #[Route('/screens/{screen}/layout', name: 'screen_layout', methods: ['GET'])]
    public function layout(
        string $screen,
        Request $request,
        TileRenderer $renderer,
    ): JsonResponse {
        $screenObj = $this->resolveReadableScreen($screen);
        if ($screenObj === null) {
            return $this->json(['error' => \sprintf('Unknown screen "%s".', $screen)], Response::HTTP_NOT_FOUND);
        }

        $now = time();
        $layout = $this->layoutFactory->forScreen($screenObj);
        $reservations = $layout->reservations(); // id => Position

        $payload = [
            'grid' => [
                'cols' => $screenObj->getCols(),
                'rows' => $screenObj->getRows(),
                'gap' => $screenObj->getGap(),
            ],
            'tiles' => array_map(
                static fn (Tile $tile): array => [
                    'id' => $tile->getId(),
                    'html' => $renderer->renderContent($tile),
                    'position' => [
                        'x' => $tile->getPosition()->x,
                        'y' => $tile->getPosition()->y,
                        'w' => $tile->getPosition()->w,
                        'h' => $tile->getPosition()->h,
                    ],
                    'created_at' => $tile->getCreatedAt(),
                    'expires_at' => $tile->getExpiresAt(),
                    'reserved' => isset($reservations[$tile->getId()]),
                ],
                $layout->liveTiles($now),
            ),
            // Held spots (so the display can render placeholders + an un-pin control).
            'reservations' => array_map(
                static fn (string $id, $p): array => [
                    'id' => $id,
                    'position' => ['x' => $p->x, 'y' => $p->y, 'w' => $p->w, 'h' => $p->h],
                ],
                array_keys($reservations),
                array_values($reservations),
            ),
        ];

        $response = new JsonResponse($payload);
        $response->setEtag(hash('xxh128', (string) json_encode($payload, JSON_THROW_ON_ERROR)));

        // Live, per-state endpoint: it must NOT be stored by any (shared) cache,
        // or the display flickers between a cached snapshot and the live layout.
        $response->headers->set('Cache-Control', 'no-store');

        // Returns the response with a 304 status when If-None-Match matches.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The screen to read a layout for: "main" is auto-created so it always
     * renders; any other screen must already exist (else null → 404).
     */
    private function resolveReadableScreen(string $screen): ?Screen
    {
        if ($screen === ScreenRegistry::DEFAULT_ID) {
            return $this->screens->getOrCreate($screen);
        }

        return $this->screens->get($screen);
    }
}
