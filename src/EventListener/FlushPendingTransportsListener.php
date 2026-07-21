<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drains pending async Envelope HTTP responses after the response / console command finishes.
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
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', -1024];
        }

        return $events;
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
