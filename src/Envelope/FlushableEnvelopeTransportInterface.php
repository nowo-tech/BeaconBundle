<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

/**
 * Transport that may defer HTTP completion until {@see flush()}.
 */
interface FlushableEnvelopeTransportInterface extends EnvelopeTransportInterface
{
    /**
     * Complete any pending HTTP responses (status / logging). Safe to call multiple times.
     */
    public function flush(): void;
}
