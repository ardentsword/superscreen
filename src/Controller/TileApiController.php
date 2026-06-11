<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TileRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The write/read API for the layout. See docs/BACKEND.md §3.
 *
 * Interface only — handler bodies are not implemented yet.
 */
#[Route('/api', name: 'api_')]
final class TileApiController extends AbstractController
{
    /**
     * Add or replace a tile (upsert by id). Accepts the {@see TileRequest}
     * payload; the backend will resolve size → position and persist it.
     */
    #[Route('/tiles', name: 'tiles_upsert', methods: ['POST'])]
    public function upsert(#[MapRequestPayload] TileRequest $tile): JsonResponse
    {
        // The JSON body is now mapped to TileRequest. Still TODO: resolve
        // size → position (placement), compute expiry from duration, and
        // persist via TileRepository.
        return $this->json([
            'received' => [
                'id' => $tile->getId(),
                'size' => $tile->getSize()->value,
                'duration' => $tile->getDuration(),
                'content' => $tile->getContent(),
            ],
            'note' => 'Payload mapped; not persisted yet.',
        ]);
    }

    /**
     * Remove a tile by id.
     */
    #[Route('/tiles/{id}', name: 'tiles_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        // TODO: delete the tile via TileRepository.
        return $this->notImplemented();
    }

    /**
     * The single layout snapshot the display polls: grid + live tiles, with an
     * ETag for 304 handling.
     */
    #[Route('/layout', name: 'layout', methods: ['GET'])]
    public function layout(): JsonResponse
    {
        // TODO: return grid + live tiles from TileRepository, with ETag / 304.
        return $this->notImplemented();
    }

    private function notImplemented(): JsonResponse
    {
        return $this->json(['error' => 'Not implemented'], Response::HTTP_NOT_IMPLEMENTED);
    }
}
