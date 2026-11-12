<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Worker\UnitOfWorkFrames;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function class_exists;

use const PHP_INT_MAX;

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
 * - `console.terminate` (priority -4096, after the console error capture and the pending flush), only when it ends the
 *   OUTERMOST command and no Messenger message is in flight: a command run in-process from another command or from a
 *   message handler (`Application::doRun()`) must not clear the outer unit's scope.
 *
 * Which unit is outermost is tracked by {@see UnitOfWorkFrames} (1.24.1, audits NM / NL2-v / NL2-v2), fed at priority
 * `PHP_INT_MAX` so another listener that throws or stops propagation cannot unbalance it: one frame per console run
 * (Command + input) that saves and restores the outer Messenger state, a frame left failing by a throwing
 * `console.error` listener healed at the next request, message, command or worker stop (before the handler writes its
 * own flag). The clears keep their priorities.
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
final class BeaconRequestScopeResetListener implements EventSubscriberInterface, ResetInterface
{
    /** Messenger skip event (class name; only dispatched by `messenger:failed:retry`, symfony/messenger 7.2+). */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = 'Symfony\Component\Messenger\Event\WorkerMessageSkipEvent';

    /**
     * Units of work running in this process. Deliberately not tagged `kernel.reset` (a reset between messages of a
     * `messenger:consume` command would lose the outer command; the service is not autoconfigured).
     */
    private readonly UnitOfWorkFrames $frames;

    /**
     * @param iterable<ResetInterface> $scopeState BreadcrumbBuffer, SpanBuffer, Scope and TraceIdProvider
     * @param iterable<PendingTransportRegistry> $pendingRegistries
     */
    public function __construct(
        private readonly iterable $scopeState,
        private readonly iterable $pendingRegistries = [],
    ) {
        $this->frames = new UnitOfWorkFrames();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [KernelEvents::REQUEST => [['onKernelRequestStarted', PHP_INT_MAX], ['onKernelRequest', 4096]]];

        if (class_exists(WorkerMessageReceivedEvent::class)) {
            $events[WorkerMessageReceivedEvent::class]      = [['onMessageReceivedStarted', PHP_INT_MAX], ['onMessageReceived', 4096]];
            $events[WorkerMessageHandledEvent::class]       = [['onMessageFinishedStarted', PHP_INT_MAX], ['onMessageFinished', -4096]];
            $events[WorkerMessageFailedEvent::class]        = [['onMessageFinishedStarted', PHP_INT_MAX], ['onMessageFinished', -4096]];
            $events[self::MESSENGER_WORKER_MESSAGE_SKIPPED] = ['onMessageFinishedStarted', PHP_INT_MAX];
            // A listener may skip a received message (no handled / failed event follows).
            $events[WorkerStoppedEvent::class] = ['onWorkerStopped', PHP_INT_MAX];
        }

        if (class_exists(ConsoleEvents::class)) {
            // Bookkeeping first (PHP_INT_MAX), the clear late: a listener in between cannot skip the bookkeeping.
            $events[ConsoleEvents::COMMAND]   = ['onConsoleCommand', PHP_INT_MAX];
            $events[ConsoleEvents::ERROR]     = ['onConsoleError', PHP_INT_MAX];
            $events[ConsoleEvents::TERMINATE] = [['onConsoleTerminateStarted', PHP_INT_MAX], ['onConsoleTerminate', -4096]];
        }

        return $events;
    }

    /**
     * Main request starts (bookkeeping): heals a run left failing. The main-request depth is deliberately not kept
     * (requestFinished() at once): the HTTP clears are unconditional and a command run from a controller keeps the
     * pre-1.24.1 behaviour (it is its own outermost unit).
     */
    public function onKernelRequestStarted(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->frames->requestStarted();
            $this->frames->requestFinished();
        }
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
     * A Messenger message is received (bookkeeping).
     */
    public function onMessageReceivedStarted(): void
    {
        $this->frames->messageReceived();
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
     * End of a message (handled or failed), after the bundle's own captures.
     */
    public function onMessageFinished(): void
    {
        $this->clear();
    }

    /**
     * A Messenger message is handled, failed or skipped (bookkeeping).
     */
    public function onMessageFinishedStarted(): void
    {
        $this->frames->messageFinished();
    }

    public function onWorkerStopped(): void
    {
        $this->frames->workerStopped();
    }

    /**
     * A console run starts (bookkeeping).
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->frames->commandStarted($event);
    }

    /**
     * A console run failed (bookkeeping: its frame is marked failing until its own terminate).
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->frames->commandFailed($event);
    }

    /**
     * A console run ends (bookkeeping: its frame is removed, the outermost verdict kept for the late clear).
     */
    public function onConsoleTerminateStarted(ConsoleTerminateEvent $event): void
    {
        $this->frames->commandTerminated($event);
    }

    /**
     * Clears only when the outermost command ends outside a Messenger message (after the console error capture and
     * the pending flush).
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->frames->isOutermost($event)) {
            $this->clear();
        }
    }

    /**
     * Forgets the unit-of-work bookkeeping (not the scope: see clear()). Not tagged `kernel.reset` and not relied on in
     * worker mode.
     */
    public function reset(): void
    {
        $this->frames->reset();
    }

    /**
     * Clears breadcrumbs, spans, scope tags and the trace id.
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
