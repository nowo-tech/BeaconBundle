<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Client;

use InvalidArgumentException;
use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\MessengerEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use stdClass;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BeaconClientFactoryTest extends TestCase
{
    public function testCreateReturnsNullClientWhenDisabledOrEmptyDsn(): void
    {
        $factory = new BeaconClientFactory(new BeaconDsnParser(), new MockHttpClient());

        self::assertInstanceOf(NullBeaconClient::class, $factory->create(false, 'https://key:secret@host/1', 'test', null, 'h', true, 5.0));
        self::assertInstanceOf(NullBeaconClient::class, $factory->create(true, '', 'test', null, 'h', true, 5.0));
        self::assertInstanceOf(NullBeaconClient::class, $factory->create(true, '   ', 'test', null, 'h', true, 5.0));
    }

    public function testCreateReturnsLiveClientForValidDsn(): void
    {
        $factory = new BeaconClientFactory(
            new BeaconDsnParser(),
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
        );

        $client = $factory->create(true, 'https://pubkey:secret@beacon.example.com:9444/5', 'test', '1.0', 'ci', false, 2.0);

        self::assertInstanceOf(BeaconClient::class, $client);
        self::assertTrue($client->isEnabled());
        self::assertNotNull($client->captureMessage('ping', 'info'));
    }

    public function testCreateThrowsOnInvalidDsn(): void
    {
        $factory = new BeaconClientFactory(new BeaconDsnParser(), new MockHttpClient());

        $this->expectException(InvalidBeaconDsnException::class);
        $factory->create(true, 'https://pubkey:secret@beacon.example.com/not-a-number', 'test', null, 'h', true, 5.0);
    }

    public function testCreateSyncTransportReturnsEnvelopeTransport(): void
    {
        $factory   = new BeaconClientFactory(new BeaconDsnParser(), new MockHttpClient());
        $transport = $factory->createSyncTransport(true, 'https://pubkey:secret@beacon.example.com/5', false, 1.5);

        self::assertSame(5, $transport->getDsn()->getProjectId());
        self::assertSame('https://beacon.example.com/api/5/envelope/', $transport->getDsn()->getEnvelopeUrl());
    }

    public function testCreateSyncTransportRequiresEnabledDsn(): void
    {
        $factory = new BeaconClientFactory(new BeaconDsnParser(), new MockHttpClient());

        $this->expectException(InvalidArgumentException::class);
        $factory->createSyncTransport(false, 'https://pubkey:secret@beacon.example.com/5');
    }

    public function testCreateWithAsyncTransportRegistersPending(): void
    {
        $registry = new PendingTransportRegistry();
        $factory  = new BeaconClientFactory(
            new BeaconDsnParser(),
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
            null,
            null,
            null,
            null,
            null,
            null,
            $registry,
        );

        $client = $factory->create(
            true,
            'https://pubkey:secret@beacon.example.com/5',
            'test',
            null,
            'h',
            true,
            5.0,
            [],
            null,
            'async',
        );

        self::assertInstanceOf(BeaconClient::class, $client);
        self::assertNotNull($client->captureMessage('async-ping', 'info'));
        $registry->flush();
    }

    public function testCreateWithMessengerFallsBackToAsyncWithoutBus(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $registry = new PendingTransportRegistry();
        $factory  = new BeaconClientFactory(
            new BeaconDsnParser(),
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
            $logger,
            null,
            null,
            null,
            null,
            null,
            $registry,
        );

        $client = $factory->create(
            true,
            'https://pubkey:secret@beacon.example.com/5',
            'test',
            null,
            'h',
            true,
            5.0,
            [],
            null,
            'messenger',
        );

        self::assertInstanceOf(BeaconClient::class, $client);
        self::assertNotNull($client->captureMessage('msg', 'info'));
        $registry->flush();
    }

    public function testCreateWithMessengerUsesMessageBus(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $factory = new BeaconClientFactory(
            new BeaconDsnParser(),
            new MockHttpClient(),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $bus,
        );

        $client = $factory->create(
            true,
            'https://pubkey:secret@beacon.example.com/5',
            'test',
            null,
            'h',
            true,
            5.0,
            [],
            null,
            'messenger',
        );

        self::assertInstanceOf(BeaconClient::class, $client);
        self::assertNotNull($client->captureMessage('via-bus', 'info'));
    }

    public function testWrapMessengerFallsBackWhenBusLacksInterface(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $factory = new BeaconClientFactory(
            new BeaconDsnParser(),
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
            $logger,
            null,
            null,
            null,
            null,
            null,
            new PendingTransportRegistry(),
            new stdClass(),
        );

        $client = $factory->create(
            true,
            'https://pubkey:secret@beacon.example.com/5',
            'test',
            null,
            'h',
            true,
            5.0,
            [],
            null,
            'messenger',
        );

        self::assertInstanceOf(BeaconClient::class, $client);
    }

    public function testWrapTransportModesViaReflection(): void
    {
        $bus     = $this->createMock(MessageBusInterface::class);
        $factory = new BeaconClientFactory(
            new BeaconDsnParser(),
            new MockHttpClient(),
            null,
            null,
            null,
            null,
            null,
            null,
            new PendingTransportRegistry(),
            $bus,
        );

        $dsn  = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $sync = new EnvelopeTransport(new MockHttpClient(), $dsn);

        $wrap = new ReflectionMethod($factory, 'wrapTransport');
        $wrap->setAccessible(true);

        self::assertInstanceOf(AsyncEnvelopeTransport::class, $wrap->invoke($factory, $sync, 'async'));
        self::assertInstanceOf(MessengerEnvelopeTransport::class, $wrap->invoke($factory, $sync, 'messenger'));
        self::assertSame($sync, $wrap->invoke($factory, $sync, 'sync'));
    }
}
