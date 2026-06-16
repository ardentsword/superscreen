<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\ApiKey\ApiKeyRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Requires a valid X-Api-Key header on write requests under /api. Reads stay
 * open. Enforcement auto-activates only once at least one key exists, so the
 * API can't lock itself out before any key is created. See docs/BACKEND.md §8.
 */
final readonly class ApiKeySubscriber implements EventSubscriberInterface
{
    /** Request attribute holding the authenticated key id (for attribution). */
    public const string ATTRIBUTE = '_api_key_id';

    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private ApiKeyRepository $keys,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 8]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!\in_array($request->getMethod(), self::WRITE_METHODS, true)
            || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Open until the first key is created; once any exist, enforce.
        if (!$this->keys->hasAny()) {
            return;
        }

        $keyId = $this->keys->resolve((string) $request->headers->get('X-Api-Key', ''));
        if ($keyId === null) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Invalid or missing API key.'],
                Response::HTTP_UNAUTHORIZED,
            ));

            return;
        }

        // Expose the authenticated key id for per-tile attribution.
        $request->attributes->set(self::ATTRIBUTE, $keyId);
    }
}
