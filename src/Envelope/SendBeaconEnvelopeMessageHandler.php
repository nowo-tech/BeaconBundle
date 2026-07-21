<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

/**
 * Worker-side handler: POSTs queued Envelope bodies with the synchronous HTTP transport.
 */
final class SendBeaconEnvelopeMessageHandler
{
    public function __construct(
        private readonly EnvelopeTransport $transport,
    ) {
    }

    public function __invoke(SendBeaconEnvelopeMessage $message): void
    {
        $this->transport->send($message->envelopeBody);
    }
}
