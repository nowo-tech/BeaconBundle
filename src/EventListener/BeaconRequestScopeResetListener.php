<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function class_exists;

/**
 * Starts every unit of work with empty breadcrumbs, spans, scope tags and no trace id (REQ-RUNTIME-002).
 *
 * Boundaries:
 * - main `kernel.request` (priority 4096, before {@see BeaconTraceRequestListener} seeds the trace id); sub-requests
 *   (fragments, ESI) share the main request's data and are left untouched;
 * - Messenger `WorkerMessageReceivedEvent` (priority 4096, before the message is handled) and
 *   `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (priority -4096, AFTER the bundle's failure capture
 *   (-150), Scheduler check-in (-512), message transaction (-1024) and pending flush (-2048), so an event captured
 *   for a failed message still carries that message's breadcrumbs, tags and trace id);
 * - `console.terminate` (priority -4096, after the console error capture and the pending flush).
 *
 * The same services are also tagged `kernel.reset` (they implement {@see ResetInterface}); this listener keeps them
 * correct where the services resetter does not run: a host that removes or bypasses `services_resetter` (or drives
 * `http_kernel` directly), Messenger consumers started with `--no-reset`, and long-running console commands.
 *
 * The services arrive as lazy iterators of `IGNORE_ON_UNINITIALIZED_REFERENCE` references: a boundary only clears
 * instances that already exist and never builds one. At the start of a request or message it also drains any leftover
 * async Envelope POSTs whose terminate did not run.
 *
 * @internal registered by the bundle; the constructor is not a public API
 */
final readonly class BeaconRequestScopeResetListener implements EventSubscriberInterface
{
    /**
     * @param iterable<ResetInterface> $scopeState BreadcrumbBuffer, SpanBuffer, Scope and TraceIdProvider
     * @param iterable<PendingTransportRegistry> $pendingRegistries
     */
    public function __construct(
        private iterable $scopeState,
        private iterable $pendingRegistries = [],
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [KernelEvents::REQUEST => ['onKernelRequest', 4096]];

        if (class_exists(WorkerMessageReceivedEvent::class)) {
            $events[WorkerMessageReceivedEvent::class] = ['onMessageReceived', 4096];
            $events[WorkerMessageHandledEvent::class]  = ['clear', -4096];
            $events[WorkerMessageFailedEvent::class]   = ['clear', -4096];
        }

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = ['clear', -4096];
        }

        return $events;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->flushPending();
        $this->clear();
    }

    /**
     * Start of a Messenger message: nothing from the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        $this->flushPending();
        $this->clear();
    }

    /**
     * End of a message or command: clears breadcrumbs, spans, scope tags and the trace id.
     */
    public function clear(): void
    {
        foreach ($this->scopeState as $state) {
            $state->reset();
        }
    }

    private function flushPending(): void
    {
        foreach ($this->pendingRegistries as $registry) {
            $registry->flush();
        }
    }
}
