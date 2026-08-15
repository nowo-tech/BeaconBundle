<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger;

use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Propagates {@see BeaconTraceStamp} on dispatch and restores it on consume.
 */
final class BeaconTraceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TraceIdProvider $traceIdProvider,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(BeaconTraceStamp::class);
        if ($stamp instanceof BeaconTraceStamp) {
            $this->traceIdProvider->set($stamp->traceId);
        } else {
            $envelope = $envelope->with(new BeaconTraceStamp($this->traceIdProvider->getOrCreate()));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
