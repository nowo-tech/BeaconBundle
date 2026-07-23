<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    public function testSendReturnsFalseWhenStartRequestFails(): void
    {
        $dsn        = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->method('request')
            ->willThrowException(new class('fail') extends RuntimeException implements TransportExceptionInterface {
            });

        $async = new AsyncEnvelopeTransport(new EnvelopeTransport($httpClient, $dsn, true, 5.0, null, 'beacon-bundle/test'));

        self::assertFalse($async->send('body'));
    }
}
