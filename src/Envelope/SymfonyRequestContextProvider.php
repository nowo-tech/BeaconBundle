<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\Beacon\Envelope\EnvelopeBuilder as CoreEnvelopeBuilder;
use Nowo\Beacon\Request\RequestContextProviderInterface;
use Nowo\Beacon\Request\RequestSnapshot;
use Nowo\BeaconBundle\Support\HttpRequestSnapshot;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Current Symfony request → beacon-php {@see RequestSnapshot} (raw values; the builder redacts them).
 *
 * Route, controller (including `[object, method]` controllers), query keys and client come from
 * {@see HttpRequestSnapshot}, so `request` / `extra.http` keep BeaconBundle's values. Only the header allow-list of
 * {@see CoreEnvelopeBuilder::REQUEST_HEADERS} is copied.
 *
 * @internal used by {@see EnvelopeBuilder}
 */
final class SymfonyRequestContextProvider implements RequestContextProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly bool $includeClient,
    ) {
    }

    public function getRequest(): ?RequestSnapshot
    {
        $request = $this->currentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $http = HttpRequestSnapshot::fromRequest($request, null, $this->includeClient);

        $headers = [];
        foreach (CoreEnvelopeBuilder::REQUEST_HEADERS as $name) {
            if ($request->headers->has($name)) {
                $headers[$name] = (string) $request->headers->get($name);
            }
        }

        return new RequestSnapshot(
            url: $request->getUri(),
            method: $request->getMethod(),
            queryString: $request->getQueryString(),
            route: $http['route'] ?? null,
            controller: $http['controller'] ?? null,
            // Raw keys (integers stay integers, as in BeaconBundle's `query_keys`).
            queryKeys: $http['query_keys'] ?? [],
            clientIp: $http['client']['ip'] ?? null,
            userAgent: $http['client']['user_agent'] ?? null,
            headers: $headers,
        );
    }

    /**
     * The request the snapshot was taken from (also read by {@see EnvelopeBuilder} to re-apply its redaction).
     */
    public function currentRequest(): ?Request
    {
        return $this->requestStack->getCurrentRequest();
    }

    /**
     * Raw `referer` header (null when absent), as the builder reads it.
     */
    public static function referer(Request $request): ?string
    {
        return $request->headers->has('referer') ? (string) $request->headers->get('referer') : null;
    }
}
