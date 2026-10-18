<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\FailureTransport;

use Nowo\BeaconBundle\DependencyInjection\Compiler\RecordingFailureSenderPass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

use function is_int;
use function is_string;

/**
 * Wraps a failure transport inside the sender locator of Symfony's
 * `SendFailedMessageToFailureTransportListener` (only there, see {@see RecordingFailureSenderPass}).
 *
 * That listener discards the envelope returned by `send()`, so the id the message got in the failure
 * transport ({@see TransportMessageIdStamp}) would be lost. This decorator forwards the send unchanged
 * and records the id in {@see FailureTransportRecorder} — only when the failure transport can find a
 * message by id ({@see ListableReceiverInterface}: Doctrine, Redis; not AMQP / SQS / in-memory), because
 * Beacon can only act on such ids. Exceptions from the inner sender are never caught.
 */
final class RecordingFailureSender implements SenderInterface
{
    public function __construct(
        private readonly SenderInterface $inner,
        private readonly string $failureTransportName,
        private readonly FailureTransportRecorder $recorder,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        $sent = $this->inner->send($envelope);

        if (!$this->inner instanceof ListableReceiverInterface) {
            return $sent;
        }

        $original = $envelope->last(SentToFailureTransportStamp::class);
        $idStamp  = $sent->last(TransportMessageIdStamp::class);
        if (!$original instanceof SentToFailureTransportStamp || !$idStamp instanceof TransportMessageIdStamp) {
            return $sent;
        }

        $id = $idStamp->getId();
        if (is_string($id) || is_int($id)) {
            $this->recorder->record($original->getOriginalReceiverName(), $this->failureTransportName, $id);
        }

        return $sent;
    }
}
