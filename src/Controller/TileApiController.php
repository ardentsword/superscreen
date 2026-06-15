<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Tile;
use App\Dto\TileRequest;
use App\Service\Layout\LayoutService;
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
        #[MapRequestPayload] TileRequest $request,
        LayoutService $layout,
    ): JsonResponse {
        try {
            $result = $layout->upsert($request, time());
        } catch (UnknownContentTypeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * Remove a tile by id (placed or queued). Idempotent. Frees space, so the
     * queue is drained afterwards.
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
        #[Autowire('%app.grid.cols%')] int $cols,
        #[Autowire('%app.grid.rows%')] int $rows,
        #[Autowire('%app.grid.gap%')] int $gap,
    ): JsonResponse {
        $payload = [
            'grid' => ['cols' => $cols, 'rows' => $rows, 'gap' => $gap],
            'tiles' => array_map(
                static fn (Tile $tile): array => [
                    'id' => $tile->getId(),
                    'content' => ['type' => $tile->getContentType()->value, ...$tile->getContent()],
                    'position' => [
                        'x' => $tile->getPosition()->x,
                        'y' => $tile->getPosition()->y,
                        'w' => $tile->getPosition()->w,
                        'h' => $tile->getPosition()->h,
                    ],
                ],
                $layout->liveTiles(time()),
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
