<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PendingTransportRegistryTest extends TestCase
{
    public function testFlushIsNoopWithoutRegistration(): void
    {
        $this->expectNotToPerformAssertions();

        $registry = new PendingTransportRegistry();
        $registry->flush();
    }

    public function testRegisterAndFlushDelegates(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 202]));
        $async  = new AsyncEnvelopeTransport(new EnvelopeTransport($client, $dsn, true, 5.0, null, 'beacon-bundle/test'));

        $registry = new PendingTransportRegistry();
        $registry->register($async);
        self::assertTrue($async->send('body'));
        $registry->flush();

        self::assertSame($dsn, $async->getDsn());
    }
}
