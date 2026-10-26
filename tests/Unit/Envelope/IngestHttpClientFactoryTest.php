<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\IngestHttpClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function microtime;

final class IngestHttpClientFactoryTest extends TestCase
{
    public function testReturnsTheClientUnchangedWhenRetriesAreDisabled(): void
    {
        $httpClient = new MockHttpClient();

        self::assertSame($httpClient, IngestHttpClientFactory::create($httpClient, 0));
        self::assertSame($httpClient, IngestHttpClientFactory::create($httpClient, 2, statusCodes: []));
        self::assertInstanceOf(RetryableHttpClient::class, IngestHttpClientFactory::create($httpClient));
    }

    public function testServerErrorsAreRetriedUntilBeaconAccepts(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 202]),
        ]);

        $result = $this->transport(IngestHttpClientFactory::create($httpClient, 2, 0, jitter: 0.0))->sendDetailed("envelope\n");

        self::assertTrue($result->isAccepted());
        self::assertSame(202, $result->getStatusCode());
        self::assertSame(3, $httpClient->getRequestsCount());
    }

    public function testGivesUpAfterMaxRetriesAndReportsTheLastStatus(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 502]),
            new MockResponse('', ['http_code' => 202]),
        ]);

        $result = $this->transport(IngestHttpClientFactory::create($httpClient, 1, 0, jitter: 0.0))->sendDetailed("envelope\n");

        self::assertFalse($result->isAccepted());
        self::assertSame(502, $result->getStatusCode());
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testWaitsBetweenAttempts(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 202]),
        ]);
        $transport = $this->transport(IngestHttpClientFactory::create($httpClient, 2, 100, 2.0, 1000, 0.0));

        $start  = microtime(true);
        $result = $transport->sendDetailed("envelope\n");

        // 100 ms before the first retry, 200 ms before the second.
        self::assertTrue($result->isAccepted());
        self::assertGreaterThanOrEqual(0.29, microtime(true) - $start);
    }

    public function testAsyncTransportRetriesWhenItFlushes(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 202]),
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $async = new AsyncEnvelopeTransport(new EnvelopeTransport(
            IngestHttpClientFactory::create($httpClient, 2, 0, jitter: 0.0),
            (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com:9444/5'),
            logger: $logger,
        ));

        self::assertTrue($async->send("envelope\n"));
        $async->flush();

        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testClientErrorsAndTransportErrorsAreNotRetried(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 400]),
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('', ['http_code' => 202]),
        ]);
        $transport = $this->transport(IngestHttpClientFactory::create($httpClient, 2, 0, jitter: 0.0));

        self::assertSame(400, $transport->sendDetailed("envelope\n")->getStatusCode());
        self::assertFalse($transport->sendDetailed("envelope\n")->isAccepted());
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    private function transport(HttpClientInterface $httpClient): EnvelopeTransport
    {
        return new EnvelopeTransport($httpClient, (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com:9444/5'));
    }
}
