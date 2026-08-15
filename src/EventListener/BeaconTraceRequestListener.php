<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Seeds {@see TraceIdProvider} from {@see TraceIdProvider::HEADER} or generates one.
 */
final class BeaconTraceRequestListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TraceIdProvider $traceIdProvider,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 100]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request    = $event->getRequest();
        $fromHeader = TraceIdProvider::fromMixed($request->headers->get(TraceIdProvider::HEADER));
        if ($fromHeader !== null) {
            $this->traceIdProvider->set($fromHeader);
        }

        $traceId = $this->traceIdProvider->getOrCreate();
        $request->attributes->set(TraceIdProvider::REQUEST_ATTRIBUTE, $traceId);

        if (!$request->headers->has(TraceIdProvider::HEADER)) {
            $request->headers->set(TraceIdProvider::HEADER, $traceId);
        }
    }
}
