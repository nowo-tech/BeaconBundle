<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\MessengerEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerEnvelopeTransportTest extends TestCase
{
    public function testSendDispatchesMessage(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $message): bool {
                return $message instanceof SendBeaconEnvelopeMessage
                    && $message->envelopeBody === "header\nitem\n";
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $transport = new MessengerEnvelopeTransport($bus, $dsn);
        self::assertTrue($transport->send("header\nitem\n"));
        self::assertSame($dsn, $transport->getDsn());
    }
}
