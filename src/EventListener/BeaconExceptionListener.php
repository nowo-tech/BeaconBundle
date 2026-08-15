<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Support\HttpRequestSnapshot;
use Nowo\BeaconBundle\Support\IgnoredRequestPath;
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
        private readonly bool $sendRequest = true,
        /** @var list<string> */
        private readonly array $ignorePaths = IgnoredRequestPath::DEFAULTS,
        private readonly bool $sendClient = false,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    /**
     * Capture the uncaught exception unless ignored or the client is disabled.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->enabled || !$this->client->isEnabled()) {
            return;
        }

        if (IgnoredRequestPath::matches($event->getRequest()->getPathInfo(), $this->ignorePaths)) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($this->shouldIgnore($throwable)) {
            return;
        }

        $extra = [];
        if ($this->sendRequest) {
            $request = $event->getRequest();
            $extra   = [
                'request_uri'    => $request->getUri(),
                'request_method' => $request->getMethod(),
            ];
            $http = HttpRequestSnapshot::fromRequest($request, $throwable, $this->sendClient);
            if ($http !== []) {
                $extra['http'] = $http;
            }
        }

        $this->client->captureException($throwable, $extra);
    }

    /**
     * Whether `$throwable` matches any configured ignore class.
     */
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
