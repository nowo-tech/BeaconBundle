<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * A transport that cannot list or find a message by id (like AMQP or SQS). Symfony 8.2's in-memory transport is
 * listable, so the tests that need a non-listable transport use this one.
 */
final class NonListableTransport implements TransportInterface
{
    /**
     * @return iterable<Envelope>
     */
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
