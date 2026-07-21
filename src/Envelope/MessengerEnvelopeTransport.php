<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use InvalidArgumentException;
use Nowo\BeaconBundle\Dsn\BeaconDsn;

use function method_exists;

/**
 * Dispatches Envelope bodies onto the Symfony Messenger bus (true async / queue).
 *
 * Expects an object with `dispatch(object $message): mixed` (Symfony MessageBusInterface).
 */
final class MessengerEnvelopeTransport implements EnvelopeTransportInterface
{
    public function __construct(
        private readonly object $messageBus,
        private readonly BeaconDsn $dsn,
    ) {
        if (!method_exists($this->messageBus, 'dispatch')) {
            throw new InvalidArgumentException('Messenger Envelope transport requires a message bus with a dispatch() method.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $envelopeBody): bool
    {
        $this->messageBus->dispatch(new SendBeaconEnvelopeMessage($envelopeBody));

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getDsn(): BeaconDsn
    {
        return $this->dsn;
    }
}
