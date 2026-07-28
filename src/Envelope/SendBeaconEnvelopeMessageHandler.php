<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker-side handler: POSTs queued Envelope bodies with the synchronous HTTP transport.
 */
#[AsMessageHandler]
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
