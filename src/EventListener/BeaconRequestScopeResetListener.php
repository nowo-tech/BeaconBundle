<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Starts every main request with empty breadcrumbs, spans, scope tags and no trace id.
 *
 * The same services are also tagged kernel.reset; this listener keeps them request-scoped when a
 * long-running runtime (e.g. FrankenPHP worker) does not run the services resetter between requests.
 * Sub-requests (fragments, ESI) share the main request's data and are left untouched.
 *
 * Also drains any leftover async Envelope POSTs from a previous request whose terminate did not run.
 */
final class BeaconRequestScopeResetListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly BreadcrumbBuffer $breadcrumbBuffer,
        private readonly SpanBuffer $spanBuffer,
        private readonly Scope $scope,
        private readonly TraceIdProvider $traceIdProvider,
        private readonly PendingTransportRegistry $pendingRegistry,
    ) {
    }

    /**
     * Runs before {@see BeaconTraceRequestListener} (100) and the request transaction listener (0).
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4096]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->pendingRegistry->flush();
        $this->breadcrumbBuffer->reset();
        $this->spanBuffer->reset();
        $this->scope->reset();
        $this->traceIdProvider->reset();
    }
}
