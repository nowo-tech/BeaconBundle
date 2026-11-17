<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\Beacon\Trace\TraceIdProvider as CoreTraceIdProvider;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Seeds the trace id provider from {@see CoreTraceIdProvider::HEADER} or generates one, and stores it in the request
 * attribute {@see REQUEST_ATTRIBUTE}.
 */
final class BeaconTraceRequestListener implements EventSubscriberInterface
{
    /**
     * Request attribute holding the trace id of the main request (moved here from the bundle's TraceIdProvider in
     * 1.27.0; same value).
     */
    public const REQUEST_ATTRIBUTE = '_beacon_trace_id';

    public function __construct(
        private readonly CoreTraceIdProvider|TraceIdProvider $traceIdProvider,
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
        $fromHeader = CoreTraceIdProvider::fromMixed($request->headers->get(CoreTraceIdProvider::HEADER));
        if ($fromHeader !== null) {
            // @igor-ignore - Not shared worker service state.
            $this->traceIdProvider->set($fromHeader);
        }

        $traceId = $this->traceIdProvider->getOrCreate();
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $traceId);

        if (!$request->headers->has(CoreTraceIdProvider::HEADER)) {
            $request->headers->set(CoreTraceIdProvider::HEADER, $traceId);
        }
    }
}
