<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

use function is_string;

/**
 * Sends a performance transaction for each main HTTP request (opt-in via config).
 */
final class BeaconRequestTransactionListener implements EventSubscriberInterface, ResetInterface
{
    private ?float $startedAt = null;

    private ?Request $request = null;

    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onKernelRequest', 0],
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];
    }

    /**
     * Start timing for main requests that are not skipped.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$this->client->isEnabled() || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->shouldSkip($request)) {
            return;
        }

        $this->startedAt = microtime(true);
        $this->request   = $request;
    }

    /**
     * Emit a Beacon transaction after the response is sent.
     */
    public function onKernelTerminate(TerminateEvent $event): void
    {
        if ($this->startedAt === null || !$this->request instanceof Request) {
            $this->reset();

            return;
        }

        $end     = microtime(true);
        $request = $this->request;
        $start   = $this->startedAt;
        $this->reset();

        $name = $request->attributes->get('_route');
        if (!is_string($name) || $name === '') {
            $name = $request->getMethod() . ' ' . $request->getPathInfo();
        }

        $this->client->captureTransaction($name, $start, $end, [], [
            'http' => [
                'method'      => $request->getMethod(),
                'status_code' => $event->getResponse()->getStatusCode(),
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->startedAt = null;
        $this->request   = null;
    }

    /**
     * Skip profiler, WDT, health checks, and static build assets.
     */
    private function shouldSkip(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/_profiler')
            || str_starts_with($path, '/_wdt')
            || str_starts_with($path, '/health/')
            || str_starts_with($path, '/build');
    }
}
