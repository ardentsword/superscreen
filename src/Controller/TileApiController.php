<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Tile;
use App\Dto\TileRequest;
use App\Service\Layout\LayoutService;
use App\Service\Layout\UnknownContentTypeException;
use App\Service\Placement\NoSpaceException;
use App\Service\SimpleDatabase\TileRepository;
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
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        $position = $result->tile->getPosition();

        return $this->json([
            'id' => $result->tile->getId(),
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
     * Remove a tile by id. Idempotent — deleting a missing tile is a no-op.
     */
    #[Route('/tiles/{id}', name: 'tiles_delete', methods: ['DELETE'])]
    public function delete(string $id, TileRepository $tiles): JsonResponse
    {
        $tiles->delete($id);

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
        TileRepository $tiles,
        #[Autowire('%app.grid.cols%')] int $cols,
        #[Autowire('%app.grid.rows%')] int $rows,
        #[Autowire('%app.grid.gap%')] int $gap,
    ): JsonResponse {
        $now = time();

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
                $tiles->findLive($now),
            ),
        ];

        $response = new JsonResponse($payload);
        $response->setEtag(hash('xxh128', (string) json_encode($payload, JSON_THROW_ON_ERROR)));
        $response->setPublic();

        // Returns the response with a 304 status when If-None-Match matches.
        $response->isNotModified($request);

        return $response;
    }
}
