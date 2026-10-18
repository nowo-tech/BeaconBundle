<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Monolog;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression: while the Beacon receiver is down, the transport's own failure log must not be
 * forwarded by the Monolog handler (each forward queued a new send that failed and logged again).
 */
final class BeaconMonologHandlerSelfLoopTest extends TestCase
{
    /** Guard: stop the chain after this many requests so a regression fails instead of recursing forever. */
    private const MAX_REQUESTS = 10;

    public function testTransportFailureLogIsNotForwardedBackToBeacon(): void
    {
        $requests = 0;
        $http     = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;
            if ($requests > self::MAX_REQUESTS) {
                return new MockResponse('', ['http_code' => 200]);
            }

            throw new TransportException('Connection refused');
        });

        [$logger, $records] = $this->wireLoggerAndClient($http, Level::Error);

        $logger->error('Application failure');

        self::assertSame(1, $requests, 'Only the application record is sent; the transport failure log is not re-sent.');
        self::assertTrue($records->hasErrorThatContains('Beacon ingest transport failed.'), 'The failure stays visible to the other handlers.');
        self::assertCount(1, array_filter(
            $records->getRecords(),
            static fn (LogRecord $record): bool => $record->message === 'Beacon ingest transport failed.',
        ));
    }

    public function testRateLimitedLogIsNotForwardedBackToBeacon(): void
    {
        $requests = 0;
        $http     = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return $requests > self::MAX_REQUESTS
                ? new MockResponse('', ['http_code' => 200])
                : new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']]);
        });

        [$logger, $records] = $this->wireLoggerAndClient($http, Level::Warning);

        $logger->warning('Application warning');

        self::assertSame(1, $requests);
        self::assertTrue($records->hasWarningThatContains('Beacon ingest rate limited'));
        $flagged = array_values(array_filter(
            $records->getRecords(),
            static fn (LogRecord $record): bool => ($record->context[EnvelopeTransport::LOG_CONTEXT_FLAG] ?? false) === true,
        ));
        self::assertCount(1, $flagged);
    }

    /**
     * Logger used both by the application and by the bundle transport, with the Beacon handler on it
     * (the production wiring: MonologBundle's `logger` service is injected into the client factory).
     *
     * @return array{0: Logger, 1: TestHandler}
     */
    private function wireLoggerAndClient(MockHttpClient $http, Level $level): array
    {
        $logger  = new Logger('app');
        $records = new TestHandler();
        $logger->pushHandler($records);

        $client = (new BeaconClientFactory(new BeaconDsnParser(), $http, $logger))
            ->create(true, 'https://pubkey:secret@beacon.example.com/5', 'test', null, 'ci', true, 1.0);
        $logger->pushHandler(new BeaconMonologHandler($client, $level));

        return [$logger, $records];
    }
}
