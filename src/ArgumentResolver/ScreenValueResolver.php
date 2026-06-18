<?php

declare(strict_types=1);

namespace App\ArgumentResolver;

use App\EventSubscriber\ScreenContextSubscriber;
use App\Screen\Screen;
use App\Service\Layout\LayoutService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Injects the per-screen LayoutService / Screen that ScreenContextSubscriber put
 * on the request into controller arguments, so actions can type-hint them
 * directly. Registered with a high priority so it wins over the default service
 * resolver for the LayoutService type. See docs/MULTI-SCREEN.md §4.
 */
final class ScreenValueResolver implements ValueResolverInterface
{
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();

        if ($type === LayoutService::class && $request->attributes->has(ScreenContextSubscriber::LAYOUT)) {
            return [$request->attributes->get(ScreenContextSubscriber::LAYOUT)];
        }

        if ($type === Screen::class && $request->attributes->has(ScreenContextSubscriber::SCREEN)) {
            return [$request->attributes->get(ScreenContextSubscriber::SCREEN)];
        }

        return [];
    }
}
