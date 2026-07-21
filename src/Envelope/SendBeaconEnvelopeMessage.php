<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

/**
 * Messenger payload: raw Envelope NDJSON body to POST asynchronously via a worker.
 */
final class SendBeaconEnvelopeMessage
{
    public function __construct(
        public readonly string $envelopeBody,
    ) {
    }
}
