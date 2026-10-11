<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Drains pending async Envelope HTTP responses after the response / console command finishes,
 * and after each Messenger message in long-running workers (which never reach console terminate
 * until they stop — events captured in a worker would otherwise stay queued).
 *
 * Terminate priority is lower than {@see BeaconRequestTransactionListener} (-1024) so an
 * opt-in auto HTTP transaction can enqueue its POST before this listener drains the queue.
 */
final class FlushPendingTransportsListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly PendingTransportRegistry $registry,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::TERMINATE => ['onKernelTerminate', -2048],
        ];

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', -2048];
        }

        if (class_exists(WorkerMessageHandledEvent::class)) {
            // After the failure / retry / transaction listeners (0 … -1024) have enqueued their envelopes.
            $events[WorkerMessageHandledEvent::class] = ['flush', -2048];
            $events[WorkerMessageFailedEvent::class]  = ['flush', -2048];
            $events[WorkerStoppedEvent::class]        = ['flush', -2048];
        }

        return $events;
    }

    /**
     * Drain pending envelopes (Messenger worker events).
     */
    public function flush(): void
    {
        $this->registry->flush();
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->registry->flush();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->registry->flush();
    }
}
