<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

/**
 * Holds the process-scoped flushable transport so terminate listeners can drain pending POSTs.
 */
final class PendingTransportRegistry
{
    private ?FlushableEnvelopeTransportInterface $transport = null;

    /**
     * Register the active flushable transport (replaces any previous registration).
     */
    public function register(FlushableEnvelopeTransportInterface $transport): void
    {
        // @igor-ignore - Not shared worker service state.
        $this->transport = $transport;
    }

    /**
     * Flush pending Envelope HTTP responses when a flushable transport is registered.
     */
    public function flush(): void
    {
        $this->transport?->flush();
    }
}
