<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class EnvelopeTransportTest extends TestCase
{
    public function testSendReturnsTrueForSuccessfulResponses(): void
    {
        $dsn        = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com:9444/5');
        $requests   = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });

        $transport = new EnvelopeTransport($httpClient, $dsn);

        self::assertTrue($transport->send("header\nitem\npayload\n"));
        self::assertSame($dsn, $transport->getDsn());
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://beacon.example.com:9444/api/5/envelope/', $requests[0]['url']);
        self::assertContains(
            'X-Beacon-Auth: Beacon beacon_key=pubkey, beacon_secret=secret',
            $requests[0]['options']['headers'],
        );
    }

    public function testSendReturnsFalseAndLogsRateLimitFor429(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Beacon ingest rate limited (HTTP 429). Respect Retry-After before retrying.',
                self::callback(static function (array $context) use ($dsn): bool {
                    return $context['status'] === 429
                        && $dsn->getEnvelopeUrl() === $context['url']
                        && ($context['retry_after'] ?? null) === '60';
                }),
            );

        $transport = new EnvelopeTransport(
            new MockHttpClient(new MockResponse('', [
                'http_code'        => 429,
                'response_headers' => ['Retry-After' => '60'],
            ])),
            $dsn,
            true,
            5.0,
            $logger,
        );

        self::assertFalse($transport->send("header\nitem\npayload\n"));
    }

    public function testSendReturnsFalseAndLogsAuthWarningFor403(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Beacon ingest authentication rejected. Confirm BEACON_DSN includes public:secret and matches the project.',
                self::callback(static function (array $context) use ($dsn): bool {
                    return $context['status'] === 403
                        && $dsn->getEnvelopeUrl() === $context['url'];
                }),
            );

        $transport = new EnvelopeTransport(
            new MockHttpClient(new MockResponse('', ['http_code' => 403])),
            $dsn,
            true,
            5.0,
            $logger,
        );

        self::assertFalse($transport->send("header\nitem\npayload\n"));
    }

    public function testSendReturnsFalseAndLogsErrorOnTransportException(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Beacon ingest transport failed.',
                self::callback(static function (array $context) use ($dsn): bool {
                    return $context['exception'] === 'network down'
                        && $dsn->getEnvelopeUrl() === $context['url'];
                }),
            );

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException(new class('network down') extends RuntimeException implements TransportExceptionInterface {
            });

        $transport = new EnvelopeTransport($httpClient, $dsn, true, 5.0, $logger);

        self::assertFalse($transport->send("header\nitem\npayload\n"));
    }

    public function testFinalizeResponseLogsWhenStatusCodeThrows(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Beacon ingest transport failed.',
                self::callback(static function (array $context) use ($dsn): bool {
                    return $context['exception'] === 'status failed'
                        && $dsn->getEnvelopeUrl() === $context['url'];
                }),
            );

        $response = $this->createMock(ResponseInterface::class);
        $response
            ->method('getStatusCode')
            ->willThrowException(new class('status failed') extends RuntimeException implements TransportExceptionInterface {
            });

        $transport = new EnvelopeTransport(new MockHttpClient(), $dsn, true, 5.0, $logger);

        self::assertFalse($transport->finalizeResponse($response));
    }

    public function testSendPassesVerifyPeerAndTimeoutOptions(): void
    {
        $dsn             = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com:9444/5');
        $capturedOptions = [];
        $httpClient      = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                $dsn->getEnvelopeUrl(),
                self::callback(static function (array $options) use (&$capturedOptions): bool {
                    $capturedOptions = $options;

                    return true;
                }),
            )
            ->willReturn(new MockResponse('', ['http_code' => 204]));

        $transport = new EnvelopeTransport($httpClient, $dsn, false, 2.5, null, 'beacon-bundle/test');

        self::assertTrue($transport->send("header\nitem\npayload\n"));
        self::assertSame('application/x-beacon-envelope', $capturedOptions['headers']['Content-Type']);
        self::assertSame('beacon-bundle/test', $capturedOptions['headers']['User-Agent']);
        self::assertSame(
            'Beacon beacon_key=pubkey, beacon_secret=secret',
            $capturedOptions['headers']['X-Beacon-Auth'],
        );
        self::assertSame("header\nitem\npayload\n", $capturedOptions['body']);
        self::assertSame(2.5, $capturedOptions['timeout']);
        self::assertSame(2.5, $capturedOptions['max_duration']);
        self::assertFalse($capturedOptions['verify_peer']);
        self::assertFalse($capturedOptions['verify_host']);
    }

    public function testSendWorksWithoutLogger(): void
    {
        $dsn       = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $transport = new EnvelopeTransport(
            new MockHttpClient(new MockResponse('', ['http_code' => 400])),
            $dsn,
            true,
            5.0,
        );

        self::assertFalse($transport->send("header\nitem\npayload\n"));
    }
}
