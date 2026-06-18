<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Controller\TileApiController;
use App\Service\Layout\LayoutServiceFactory;
use App\Service\Screen\ScreenException;
use App\Service\Screen\ScreenRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the screen for a tile/layout request from the {screen} route param
 * and stashes the per-screen LayoutService (and Screen) on the request, so the
 * controller receives them ready-made (see App\ArgumentResolver\ScreenValueResolver).
 *
 * Request attributes are request-scoped, so this never leaks between requests;
 * the console commands and other controllers keep using LayoutServiceFactory /
 * ScreenRegistry directly. Runs after the router (priority < 32) so {screen} and
 * _controller are populated. See docs/MULTI-SCREEN.md §4.
 */
final readonly class ScreenContextSubscriber implements EventSubscriberInterface
{
    /** Request attributes carrying the resolved screen + its layout service. */
    public const string SCREEN = '_screen';
    public const string LAYOUT = '_layout';

    public function __construct(
        private ScreenRegistry $screens,
        private LayoutServiceFactory $layouts,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only the tile/layout actions consume a per-screen LayoutService.
        $controller = $request->attributes->get('_controller');
        if (!\is_string($controller) || !str_starts_with($controller, TileApiController::class)) {
            return;
        }

        $screenId = (string) $request->attributes->get('screen', ScreenRegistry::DEFAULT_ID);
        // Writes (and the always-present "main") create on first use; reading any
        // other unknown screen is a 404.
        $createOnMiss = $request->getMethod() !== 'GET' || $screenId === ScreenRegistry::DEFAULT_ID;

        try {
            $screen = $createOnMiss ? $this->screens->getOrCreate($screenId) : $this->screens->get($screenId);
        } catch (ScreenException $e) {
            $event->setResponse(new JsonResponse(['error' => $e->getMessage()], $e->statusCode));

            return;
        }

        if ($screen === null) {
            $event->setResponse(new JsonResponse(
                ['error' => \sprintf('Unknown screen "%s".', $screenId)],
                Response::HTTP_NOT_FOUND,
            ));

            return;
        }

        $request->attributes->set(self::SCREEN, $screen);
        $request->attributes->set(self::LAYOUT, $this->layouts->forScreen($screen));
    }
}
