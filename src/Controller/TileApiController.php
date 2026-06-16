<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MoveRequest;
use App\Dto\Tile;
use App\Dto\TileRequest;
use App\EventSubscriber\ApiKeySubscriber;
use App\Service\Display\TileRenderer;
use App\Service\Layout\LayoutService;
use App\Service\Layout\TileLimitException;
use App\Service\Layout\UnknownContentTypeException;
use App\Service\Placement\NoSpaceException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The write/read API for the layout. See docs/BACKEND.md §3.
 */
#[Route('/api', name: 'api_')]
final class TileApiController extends AbstractController
{
    /**
     * Add or replace a tile (upsert by id). Accepts the {@see TileRequest}
     * payload; the backend will resolve size → position and persist it.
     */
    #[Route('/tiles', name: 'tiles_upsert', methods: ['POST'])]
    public function upsert(
        Request $httpRequest,
        #[MapRequestPayload] TileRequest $request,
        LayoutService $layout,
    ): JsonResponse {
        $apiKeyId = $httpRequest->attributes->get(ApiKeySubscriber::ATTRIBUTE);

        try {
            $result = $layout->upsert($request, time(), \is_string($apiKeyId) ? $apiKeyId : null);
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
    #[Route('/tiles/{id}/position', name: 'tiles_move', methods: ['PATCH'])]
    public function move(
        string $id,
        #[MapRequestPayload] MoveRequest $move,
        LayoutService $layout,
    ): JsonResponse {
        try {
            $tile = $layout->move($id, $move->getX(), $move->getY(), time());
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
    #[Route('/tiles/{id}/reservation', name: 'tiles_reserve', methods: ['PUT'])]
    public function reserve(string $id, LayoutService $layout): JsonResponse
    {
        try {
            $position = $layout->reserve($id);
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
    #[Route('/tiles/{id}/reservation', name: 'tiles_unreserve', methods: ['DELETE'])]
    public function unreserve(string $id, LayoutService $layout): JsonResponse
    {
        $layout->unreserve($id, time());

        return $this->json(['id' => $id, 'reserved' => false]);
    }

    /**
     * Remove a tile by id (placed or queued). Idempotent. Frees space, so the
     * queue is drained afterwards. A reservation for the id is kept (persistent);
     * release it via DELETE /api/tiles/{id}/reservation.
     */
    #[Route('/tiles/{id}', name: 'tiles_delete', methods: ['DELETE'])]
    public function delete(string $id, LayoutService $layout): JsonResponse
    {
        $layout->delete($id, time());

        return $this->json(['deleted' => $id]);
    }

    /**
     * The single layout snapshot the display polls: grid + live tiles, with an
     * ETag so unchanged polls return 304. Hashing the body means time-based
     * expiry also flips the ETag. See docs/BACKEND.md §6.
     */
    #[Route('/layout', name: 'layout', methods: ['GET'])]
    public function layout(
        Request $request,
        LayoutService $layout,
        TileRenderer $renderer,
        #[Autowire('%app.grid.cols%')] int $cols,
        #[Autowire('%app.grid.rows%')] int $rows,
        #[Autowire('%app.grid.gap%')] int $gap,
    ): JsonResponse {
        $now = time();
        $reservations = $layout->reservations(); // id => Position

        $payload = [
            'grid' => ['cols' => $cols, 'rows' => $rows, 'gap' => $gap],
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
        // The display still revalidates via If-None-Match against the ETag, so
        // 304s keep working — we just forbid storing the body.
        $response->headers->set('Cache-Control', 'no-store');

        // Returns the response with a 304 status when If-None-Match matches.
        $response->isNotModified($request);

        return $response;
    }
}
