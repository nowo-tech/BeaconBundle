<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Support\HttpRequestSnapshot;
use Nowo\BeaconBundle\Support\IgnoredException;
use Nowo\BeaconBundle\Support\IgnoredRequestPath;
use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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
        private readonly bool $ignoreHttpClientErrors = false,
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
        if (IgnoredException::matches($throwable, $this->ignoreExceptions, $this->ignoreHttpClientErrors)) {
            return;
        }

        $extra = [];
        if ($this->sendRequest) {
            $request = $event->getRequest();
            $extra   = [
                'request_uri'    => SensitiveValueRedactor::redactUrl($request->getUri()),
                'request_method' => $request->getMethod(),
            ];
            $http = HttpRequestSnapshot::fromRequest($request, $throwable, $this->sendClient);
            if ($http !== []) {
                $extra['http'] = $http;
            }
        }

        $this->client->captureException($throwable, $extra);
    }
}
