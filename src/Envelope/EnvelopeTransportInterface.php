<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsn;

/**
 * Sends a ready Envelope body to Beacon ingest.
 */
interface EnvelopeTransportInterface
{
    /**
     * POST (or queue) an Envelope body. Returns true when delivery was accepted or successfully queued.
     */
    public function send(string $envelopeBody): bool;

    /**
     * DSN used to build the ingest URL and envelope header.
     */
    public function getDsn(): BeaconDsn;
}
