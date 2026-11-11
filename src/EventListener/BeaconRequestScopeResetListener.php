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
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function class_exists;
use function max;

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
 *   message handler (`Application::doRun()`) must not clear the outer unit's scope. The depth is counted on
 *   `console.command` / `console.terminate`; the in-flight flag is set on message received and unset on handled / failed.
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
final class BeaconRequestScopeResetListener implements EventSubscriberInterface
{
    /**
     * Console commands currently running in this process (nested in-process runs count). Process-lifetime counter,
     * bounded by the nesting depth; intentionally not reset by `kernel.reset` (a reset between messages of a
     * `messenger:consume` command would lose the outer command).
     */
    private int $consoleDepth = 0;

    /** A Messenger message is being handled (between received and handled / failed). */
    private bool $messageInFlight = false;

    /**
     * @param iterable<ResetInterface> $scopeState BreadcrumbBuffer, SpanBuffer, Scope and TraceIdProvider
     * @param iterable<PendingTransportRegistry> $pendingRegistries
     */
    public function __construct(
        private readonly iterable $scopeState,
        private readonly iterable $pendingRegistries = [],
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
            $events[WorkerMessageHandledEvent::class]  = ['onMessageFinished', -4096];
            $events[WorkerMessageFailedEvent::class]   = ['onMessageFinished', -4096];
            // A listener may skip a received message (no handled / failed event follows).
            $events[WorkerStoppedEvent::class] = ['onWorkerStopped', -4096];
        }

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND]   = ['onConsoleCommand', 4096];
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', -4096];
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
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
        $this->flushPending();
        $this->clear();
    }

    /**
     * End of a message (handled or failed), after the bundle's own captures.
     */
    public function onMessageFinished(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        $this->clear();
    }

    public function onWorkerStopped(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    public function onConsoleCommand(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        ++$this->consoleDepth;
    }

    /**
     * Clears only when the outermost command ends outside a Messenger message.
     */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->consoleDepth > 0 || $this->messageInFlight) {
            return;
        }

        $this->clear();
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
