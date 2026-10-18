<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Closure;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Support\IgnoredRequestPath;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

use function is_string;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function str_starts_with;

/**
 * Sends a performance transaction for each main HTTP request (opt-in via config).
 *
 * Requests are skipped when their path matches `ignore_paths`, their route name starts with one of
 * `ignore_routes`, or they fall outside `http_transaction_sample_rate` (decided once, at request start).
 */
final class BeaconRequestTransactionListener implements EventSubscriberInterface, ResetInterface
{
    private ?float $startedAt = null;

    private ?Request $request = null;

    private readonly float $sampleRate;

    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param list<string> $ignorePaths Path prefixes skipped (defaults: {@see IgnoredRequestPath::DEFAULTS})
     * @param float $sampleRate Fraction of requests that send a transaction (clamped to 0.0–1.0)
     * @param list<string> $ignoreRoutes Route-name prefixes skipped (e.g. `ingest_`, `api_poll_`)
     * @param (Closure(): float)|null $random Returns a float in [0, 1); injectable for tests
     */
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        private readonly array $ignorePaths = IgnoredRequestPath::DEFAULTS,
        float $sampleRate = 1.0,
        private readonly array $ignoreRoutes = [],
        ?Closure $random = null,
    ) {
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
        $this->random     = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
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
     *
     * Always clears leftover state first so FrankenPHP workers without services_resetter
     * cannot carry a previous Request into an ignored path or the next terminate.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->reset();

        if (!$this->enabled || !$this->client->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        if (
            IgnoredRequestPath::matches($request->getPathInfo(), $this->ignorePaths)
            || $this->isIgnoredRoute($request)
            || !$this->isSampled()
        ) {
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
     * Whether the matched route name (set by the router listener before this one) starts with an ignored prefix.
     */
    private function isIgnoredRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route) || $route === '') {
            return false;
        }

        foreach ($this->ignoreRoutes as $prefix) {
            if ($prefix !== '' && str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isSampled(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }

        return ($this->random)() < $this->sampleRate;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->startedAt = null;
        $this->request   = null;
    }
}
