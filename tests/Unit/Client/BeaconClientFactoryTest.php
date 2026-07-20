<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Client;

use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BeaconClientFactoryTest extends TestCase
{
    public function testCreateReturnsNullClientWhenDisabledOrEmptyDsn(): void
    {
        $factory = new BeaconClientFactory(new BeaconDsnParser(), new MockHttpClient());

        self::assertInstanceOf(NullBeaconClient::class, $factory->create(false, 'https://key@host/1', 'test', null, 'h', true, 5.0));
        self::assertInstanceOf(NullBeaconClient::class, $factory->create(true, '', 'test', null, 'h', true, 5.0));
        self::assertInstanceOf(NullBeaconClient::class, $factory->create(true, '   ', 'test', null, 'h', true, 5.0));
    }

    public function testCreateReturnsLiveClientForValidDsn(): void
    {
        $factory = new BeaconClientFactory(
            new BeaconDsnParser(),
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
        );

        $client = $factory->create(true, 'https://pubkey@beacon.example.com:9444/5', 'test', '1.0', 'ci', false, 2.0);

        self::assertInstanceOf(BeaconClient::class, $client);
        self::assertTrue($client->isEnabled());
        self::assertNotNull($client->captureMessage('ping', 'info'));
    }

    public function testCreateThrowsOnInvalidDsn(): void
    {
        $factory = new BeaconClientFactory(new BeaconDsnParser(), new MockHttpClient());

        $this->expectException(InvalidBeaconDsnException::class);
        $factory->create(true, 'https://pubkey@beacon.example.com/not-a-number', 'test', null, 'h', true, 5.0);
    }
}
