<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Reports uncaught HTTP exceptions to Beacon when enabled.
 */
final class BeaconExceptionListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        /** @var list<class-string> */
        private readonly array $ignoreExceptions = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->enabled || !$this->client->isEnabled()) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($this->shouldIgnore($throwable)) {
            return;
        }

        $this->client->captureException($throwable, [
            'request_uri'    => $event->getRequest()->getUri(),
            'request_method' => $event->getRequest()->getMethod(),
        ]);
    }

    private function shouldIgnore(Throwable $throwable): bool
    {
        foreach ($this->ignoreExceptions as $class) {
            if ($throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
