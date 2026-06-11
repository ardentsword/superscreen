<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The display page rendered fullscreen by the Pi kiosk. It is a dumb, resilient
 * renderer: it polls GET /api/layout and reconciles the grid. See docs/FRONTEND.md.
 */
final class DisplayController extends AbstractController
{
    #[Route('/', name: 'display', methods: ['GET'])]
    public function index(
        #[Autowire('%app.poll_interval%')] int $pollInterval,
    ): Response {
        return $this->render('display/index.html.twig', [
            'pollInterval' => $pollInterval,
        ]);
    }
}
