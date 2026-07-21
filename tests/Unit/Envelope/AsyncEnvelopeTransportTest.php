<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AsyncEnvelopeTransportTest extends TestCase
{
    public function testSendQueuesResponseAndFlushIsIdempotent(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $calls  = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 202]);
        });
        $async = new AsyncEnvelopeTransport(
            new EnvelopeTransport($client, $dsn, true, 5.0, null, 'beacon-bundle/test'),
        );

        self::assertTrue($async->send("header\nitem\npayload\n"));
        self::assertGreaterThanOrEqual(1, $calls);

        $beforeFlush = $calls;
        $async->flush();
        $async->flush();

        self::assertSame($beforeFlush, $calls);
        self::assertSame($dsn, $async->getDsn());
    }

    public function testFlushFinalizesRejectedResponsesWithoutThrowing(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        $async  = new AsyncEnvelopeTransport(new EnvelopeTransport($client, $dsn, true, 5.0, null, 'beacon-bundle/test'));

        self::assertTrue($async->send('body'));
        $async->flush();

        self::assertSame($dsn, $async->getDsn());
    }
}
