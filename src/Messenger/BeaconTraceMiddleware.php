<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger;

use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Propagates {@see BeaconTraceStamp} on dispatch and restores it on consume.
 *
 * The bundle prepends this middleware to the buses in `nowo_beacon.messenger.trace_middleware.buses` (default
 * `messenger.bus.default`) unless `messenger.trace_middleware.enabled` is false. The service is registered whenever
 * Messenger is installed, so a host can also list it in a bus `middleware` itself; with `$enabled` false (bundle
 * disabled or empty DSN) it only passes the envelope on.
 */
final class BeaconTraceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TraceIdProvider $traceIdProvider,
        private readonly bool $enabled = true,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->enabled) {
            return $stack->next()->handle($envelope, $stack);
        }

        $stamp = $envelope->last(BeaconTraceStamp::class);
        if ($stamp instanceof BeaconTraceStamp) {
            // @igor-ignore - Not shared worker service state.
            $this->traceIdProvider->set($stamp->traceId);
        } else {
            $envelope = $envelope->with(new BeaconTraceStamp($this->traceIdProvider->getOrCreate()));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
