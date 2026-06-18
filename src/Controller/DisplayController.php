<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Screen\ScreenRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The display page rendered fullscreen by the Pi kiosk. It is a dumb, resilient
 * renderer: it polls its screen's layout and reconciles the grid. `/` is the
 * "main" screen; `/screens/{screen}` renders any other. See docs/FRONTEND.md and
 * docs/MULTI-SCREEN.md §6.
 */
final class DisplayController extends AbstractController
{
    public function __construct(
        private readonly ScreenRegistry $screens,
    ) {}

    #[Route('/', name: 'display', defaults: ['screen' => ScreenRegistry::DEFAULT_ID], methods: ['GET'])]
    #[Route('/screens/{screen}', name: 'display_screen', methods: ['GET'])]
    public function index(
        string $screen,
        #[Autowire('%app.poll_interval%')] int $pollInterval,
    ): Response {
        // "main" always renders; any other screen must already exist.
        $exists = $screen === ScreenRegistry::DEFAULT_ID
            ? (bool) $this->screens->getOrCreate($screen)
            : $this->screens->has($screen);

        if (!$exists) {
            throw $this->createNotFoundException(\sprintf('Unknown screen "%s".', $screen));
        }

        $layoutUrl = $screen === ScreenRegistry::DEFAULT_ID
            ? $this->generateUrl('api_layout')
            : $this->generateUrl('api_screen_layout', ['screen' => $screen]);

        return $this->render('display/index.html.twig', [
            'layoutUrl' => $layoutUrl,
            'pollInterval' => $pollInterval,
        ]);
    }
}
