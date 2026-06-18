<?php

declare(strict_types=1);

namespace App\Controller;

use App\Screen\Screen;
use App\Service\Screen\ScreenException;
use App\Service\Screen\ScreenRegistry;
use App\Service\Screen\ScreenStoreFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manage screens (list / create / update grid / delete). Writes are guarded by
 * ApiKeySubscriber like the rest of /api. See docs/MULTI-SCREEN.md §5.
 */
#[Route('/api/screens', name: 'api_screens_')]
final class ScreenApiController extends AbstractController
{
    // A sane cap on grid dimensions, to keep one screen from being absurd.
    private const int MAX_DIMENSION = 50;

    public function __construct(
        private readonly ScreenRegistry $screens,
        private readonly ScreenStoreFactory $stores,
    ) {}

    /**
     * List all screens and their grid config.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json([
            'screens' => array_map(self::toArray(...), $this->screens->all()),
        ]);
    }

    /**
     * Create a screen (or update it if the id already exists). Body:
     * { "id": "lobby", "name"?: "Lobby", "cols"?: 8, "rows"?: 5, "gap"?: 8 }.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = $this->body($request);
            $id = \is_string($data['id'] ?? null) ? $data['id'] : '';
            $this->screens->getOrCreate($id); // validate id + auto-create with defaults
            $screen = $this->merge($this->screens->get($id), $data);
            $this->screens->save($screen);
        } catch (ScreenException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(self::toArray($screen), Response::HTTP_CREATED);
    }

    /**
     * Update a screen's name / grid dimensions.
     */
    #[Route('/{screen}', name: 'update', methods: ['PATCH'])]
    public function update(string $screen, Request $request): JsonResponse
    {
        $existing = $this->screens->get($screen);
        if ($existing === null) {
            return $this->json(['error' => \sprintf('Unknown screen "%s".', $screen)], Response::HTTP_NOT_FOUND);
        }

        try {
            $updated = $this->merge($existing, $this->body($request));
            $this->screens->save($updated);
        } catch (ScreenException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(self::toArray($updated));
    }

    /**
     * Delete a screen and its store. The default "main" screen is protected.
     */
    #[Route('/{screen}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $screen): JsonResponse
    {
        if ($screen === ScreenRegistry::DEFAULT_ID) {
            return $this->json(
                ['error' => ScreenException::protectedScreen($screen)->getMessage()],
                Response::HTTP_CONFLICT,
            );
        }

        $this->screens->delete($screen);
        $this->stores->deleteStore($screen);

        return $this->json(['deleted' => $screen]);
    }

    /**
     * Apply the provided fields onto a screen, validating grid dimensions.
     *
     * @param array<string, mixed> $data
     */
    private function merge(Screen $screen, array $data): Screen
    {
        $name = \is_string($data['name'] ?? null) ? $data['name'] : $screen->getName();
        $cols = $this->dimension($data, 'cols', $screen->getCols());
        $rows = $this->dimension($data, 'rows', $screen->getRows());
        $gap = \array_key_exists('gap', $data) ? max(0, (int) $data['gap']) : $screen->getGap();

        return new Screen($screen->getId(), $name, $cols, $rows, $gap);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dimension(array $data, string $key, int $current): int
    {
        if (!\array_key_exists($key, $data)) {
            return $current;
        }

        $value = (int) $data[$key];
        if ($value < 1 || $value > self::MAX_DIMENSION) {
            throw ScreenException::badGrid(\sprintf('%s must be between 1 and %d.', $key, self::MAX_DIMENSION));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException on a malformed body
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Screen $screen): array
    {
        return [
            'id' => $screen->getId(),
            'name' => $screen->getName(),
            'cols' => $screen->getCols(),
            'rows' => $screen->getRows(),
            'gap' => $screen->getGap(),
        ];
    }
}
