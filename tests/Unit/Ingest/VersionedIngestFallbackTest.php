<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Ingest;

use DateTimeImmutable;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Ingest\IngestPathMode;
use Nowo\BeaconBundle\Ingest\IngestPathResolver;
use Nowo\BeaconBundle\Ingest\IngestPaths;
use Nowo\BeaconBundle\Messenger\RemoteRetry\CommandResult;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandException;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_column;
use function array_shift;
use function constant;
use function str_contains;

/**
 * Versioned ingest paths (Beacon 1.147, D-20261110-04) with the legacy fallback against older Beacon servers.
 */
final class VersionedIngestFallbackTest extends TestCase
{
    private const VERSIONED = 'https://beacon.example.com:9444/api/ingest/v1/5/envelope/';

    private const LEGACY = 'https://beacon.example.com:9444/api/5/envelope/';

    /** @var list<array{url: string, body: string}> */
    private array $requests = [];

    /** @var list<MockResponse|TransportException> */
    private array $responses = [];

    public function testNewBeaconGetsOneRequestOnTheVersionedPath(): void
    {
        $transport       = $this->transport();
        $this->responses = [$this->answer(202), $this->answer(202)];

        self::assertTrue($transport->send("one\n"));
        self::assertTrue($transport->send("two\n"));

        self::assertSame([self::VERSIONED, self::VERSIONED], $this->urls());
        self::assertSame(self::VERSIONED, $transport->getEnvelopeUrl());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function routeMissing(): iterable
    {
        yield '404' => [404];
        yield '405' => [405];
    }

    #[DataProvider('routeMissing')]
    public function testOlderBeaconFallsBackOnceThenGoesStraightToLegacy(int $missing): void
    {
        $logger          = new ArrayLogger();
        $transport       = $this->transport(logger: $logger);
        $this->responses = [$this->answer($missing), $this->answer(202), $this->answer(202)];

        $first = $transport->sendDetailed("one\n");
        self::assertTrue($first->isAccepted());
        self::assertSame(202, $first->getStatusCode());
        self::assertTrue($transport->send("two\n"));

        self::assertSame([self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
        self::assertSame(["one\n", "one\n", "two\n"], array_column($this->requests, 'body'), 'the same body is re-sent, once');
        self::assertSame([], $logger->records, 'the 404 that triggered the fallback is not logged');
        self::assertSame(self::LEGACY, $transport->getEnvelopeUrl());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function otherStatuses(): iterable
    {
        foreach ([400, 401, 403, 413, 415, 422, 408, 429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('otherStatuses')]
    public function testNoFallbackOnOtherStatuses(int $status): void
    {
        $resolver        = new IngestPathResolver();
        $logger          = new ArrayLogger();
        $transport       = $this->transport($resolver, $logger);
        $this->responses = [$this->answer($status), $this->answer(202)];

        $result = $transport->sendDetailed("one\n");
        self::assertFalse($result->isAccepted());
        self::assertSame($status, $result->getStatusCode());
        self::assertSame(self::VERSIONED, $logger->records[0]['context']['url'] ?? null, 'the log shows the URL actually used');
        // Only a Beacon answer proves the route (LO-M1).
        self::assertSame(!IngestPaths::provesRoute($status), $resolver->isProbing($this->dsn()));
        self::assertTrue($transport->send("two\n"));

        self::assertSame([self::VERSIONED, self::VERSIONED], $this->urls(), 'never re-sent, and the versioned path is kept');
    }

    public function testAuditPocTransientFirstAnswerThenOldBeacon(): void
    {
        // Audit LO PoC A: a 503 on the first probe must not pin the versioned path against Beacon < 1.147.
        $logger          = new ArrayLogger();
        $transport       = $this->transport(logger: $logger);
        $this->responses = [$this->answer(503), $this->answer(404), $this->answer(202), $this->answer(202)];

        self::assertSame(503, $transport->sendDetailed("one\n")->getStatusCode());
        self::assertTrue($transport->send("two\n"), 'delivered through the legacy path');
        self::assertTrue($transport->send("three\n"));

        self::assertSame([self::VERSIONED, self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
        self::assertCount(1, $logger->records);
    }

    public function testAuditPocKnownVersionedTargetSelfHealsOnA404(): void
    {
        // Audit LO: 404 after the versioned path was learned (stale entry, Beacon downgrade).
        $logger          = new ArrayLogger();
        $transport       = $this->transport(logger: $logger);
        $this->responses = [$this->answer(202), $this->answer(404), $this->answer(202), $this->answer(401)];

        self::assertTrue($transport->send("one\n"));
        self::assertTrue($transport->send("two\n"));
        self::assertFalse($transport->send("three\n"));

        self::assertSame([self::VERSIONED, self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
        self::assertSame(["one\n", "two\n", "two\n", "three\n"], array_column($this->requests, 'body'));
        self::assertSame(self::LEGACY, $logger->records[0]['context']['url'] ?? null);
    }

    public function testNetworkFailureIsNeitherRetriedNorRemembered(): void
    {
        $transport       = $this->transport();
        $this->responses = [new TransportException('connection refused'), $this->answer(404), $this->answer(202)];

        $failed = $transport->sendDetailed("one\n");
        self::assertFalse($failed->isAccepted());
        self::assertNull($failed->getStatusCode());
        self::assertStringContainsString('connection refused', (string) $failed->getErrorMessage());

        // Still probing: the next send can fall back.
        self::assertTrue($transport->send("two\n"));
        self::assertSame([self::VERSIONED, self::VERSIONED, self::LEGACY], $this->urls());
    }

    public function testAFailureWhileReadingTheAnswerIsNeitherRetriedNorRemembered(): void
    {
        $resolver        = new IngestPathResolver();
        $transport       = $this->transport($resolver);
        $this->responses = [
            new MockResponse('', ['error' => 'connection reset']),
            $this->answer(404),
            new MockResponse('', ['error' => 'connection reset']),
            $this->answer(404),
            new TransportException('connection refused'),
        ];

        $failed = $transport->sendDetailed("one\n");
        self::assertNull($failed->getStatusCode());
        self::assertStringContainsString('connection reset', (string) $failed->getErrorMessage());
        self::assertTrue($resolver->isProbing($this->dsn()));

        // The legacy retry fails to read, then fails to start: nothing is learned either way.
        self::assertNull($transport->sendDetailed("two\n")->getStatusCode());
        self::assertTrue($resolver->isProbing($this->dsn()));
        self::assertNull($transport->sendDetailed("three\n")->getStatusCode());
        self::assertTrue($resolver->isProbing($this->dsn()));

        self::assertSame([self::VERSIONED, self::VERSIONED, self::LEGACY, self::VERSIONED, self::LEGACY], $this->urls());
    }

    public function testStartRequestUsesTheKnownPath(): void
    {
        $resolver = new IngestPathResolver();
        $resolver->versionedAnswered($this->dsn(), 404);
        $resolver->legacyAnswered($this->dsn(), 202);
        $transport       = $this->transport($resolver);
        $this->responses = [$this->answer(202), new TransportException('connection refused')];

        $response = $transport->startRequest("one\n");
        self::assertNotNull($response);
        self::assertTrue($transport->finalizeResponse($response));
        self::assertNull($transport->startRequest("two\n"));
        self::assertSame([self::LEGACY, self::LEGACY], $this->urls());
    }

    public function testBothPathsMissingIsReportedOnceAndStopsProbing(): void
    {
        $logger          = new ArrayLogger();
        $transport       = $this->transport(logger: $logger);
        $this->responses = [$this->answer(404), $this->answer(404), $this->answer(404), $this->answer(404)];

        $result = $transport->sendDetailed("one\n");
        self::assertSame(404, $result->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertSame(self::LEGACY, $logger->records[0]['context']['url'] ?? null);
        self::assertFalse($transport->send("two\n"));

        // The versioned path is kept; its next 404 self-heals into one more legacy attempt.
        self::assertSame([self::VERSIONED, self::LEGACY, self::VERSIONED, self::LEGACY], $this->urls());
    }

    public function testForcedModes(): void
    {
        $legacy          = $this->transport(new IngestPathResolver(IngestPathMode::Legacy));
        $this->responses = [$this->answer(202)];
        self::assertTrue($legacy->send("one\n"));
        self::assertSame([self::LEGACY], $this->urls());

        $this->requests  = [];
        $versioned       = $this->transport(new IngestPathResolver(IngestPathMode::Versioned));
        $this->responses = [$this->answer(404)];
        self::assertFalse($versioned->send("one\n"));
        self::assertSame([self::VERSIONED], $this->urls(), 'versioned mode never falls back');
    }

    public function testAsyncFallsBackAtFlushAndStopsKeepingBodies(): void
    {
        $resolver        = new IngestPathResolver();
        $async           = new AsyncEnvelopeTransport($this->transport($resolver));
        $this->responses = [$this->answer(404), $this->answer(404), $this->answer(202), $this->answer(202), $this->answer(202)];

        self::assertTrue($async->send("one\n"));
        self::assertTrue($async->send("two\n"));
        $async->flush();

        self::assertSame([self::VERSIONED, self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
        self::assertSame(["one\n", "two\n", "one\n", "two\n"], array_column($this->requests, 'body'));
        self::assertFalse($resolver->useVersioned($this->dsn()));

        self::assertTrue($async->send("three\n"));
        $pending = (fn (): array => $this->pending)->call($async);
        self::assertNull($pending[0][2], 'no body is kept once the target is known');
        $async->flush();
        self::assertSame(self::LEGACY, $this->urls()[4]);
    }

    public function testResolverIsSharedWithRemoteCommandsAndTheConnectionTester(): void
    {
        $resolver        = new IngestPathResolver();
        $transport       = $this->transport($resolver);
        $this->responses = [$this->answer(404), $this->answer(202), new MockResponse('{"commands":[]}'), $this->answer(202)];

        self::assertTrue($transport->send("one\n"));
        // Same Beacon target (origin + project) as the Envelope DSN: the claim goes straight to the legacy path.
        $claim = $this->remoteClient($resolver, 'https://pk:sk@beacon.example.com:9444/5')->claim([]);
        self::assertSame([], $claim->commands);

        $tester = new BeaconConnectionTester(new BeaconDsnParser(), $this->http(), true, 'https://pk:sk@beacon.example.com:9444/5', ingestPaths: $resolver);
        $result = $tester->test();
        self::assertTrue($result->isSuccess());
        self::assertSame(self::LEGACY, $result->getTarget()['envelope_url'] ?? null);

        self::assertSame([
            self::VERSIONED,
            self::LEGACY,
            'https://beacon.example.com:9444/api/5/messenger/commands/claim',
            self::LEGACY,
        ], $this->urls());
    }

    public function testAnUnknownProjectDoesNotPinThePathForAnotherProjectOnTheSameBeacon(): void
    {
        // Two tenants, one shared resolver (worker without kernel reset), one older Beacon without /api/ingest/v1.
        $resolver        = new IngestPathResolver();
        $unknown         = new EnvelopeTransport($this->http(), (new BeaconDsnParser())->parse('https://pk:sk@beacon.example.com:9444/99'), true, 2.0, null, 'beacon-bundle/test', $resolver);
        $tenant          = $this->transport($resolver);
        $this->responses = [$this->answer(404), $this->answer(404), $this->answer(404), $this->answer(202), $this->answer(202)];

        self::assertFalse($unknown->send("one\n"));
        self::assertTrue($tenant->send("two\n"));
        self::assertTrue($tenant->send("three\n"));

        self::assertSame([
            'https://beacon.example.com:9444/api/ingest/v1/99/envelope/',
            'https://beacon.example.com:9444/api/99/envelope/',
            self::VERSIONED,
            self::LEGACY,
            self::LEGACY,
        ], $this->urls());
        self::assertTrue($resolver->useVersioned((new BeaconDsnParser())->parse('https://pk:sk@beacon.example.com:9444/99')));
        self::assertFalse($resolver->useVersioned($this->dsn()));
    }

    public function testConnectionTesterShowsTheVersionedUrlByDefault(): void
    {
        $tester = new BeaconConnectionTester(new BeaconDsnParser(), $this->http(), true, 'https://pk:sk@beacon.example.com:9444/5');

        self::assertSame(self::VERSIONED, $tester->test(true)->getTarget()['envelope_url'] ?? null);
    }

    public function testRemoteCommandsFallBackOnceThenUseTheLegacyPath(): void
    {
        $client          = $this->remoteClient(new IngestPathResolver());
        $prefix          = 'https://beacon.example.com:9447/api/';
        $project         = RemoteRetryFixtures::PROJECT;
        $this->responses = [$this->answer(404), new MockResponse('{"commands":[],"poll_after":15}'), $this->answer(200)];

        self::assertSame(15, $client->claim([])->pollAfter);
        $client->postResult(RemoteRetryFixtures::command(), CommandResult::done(CommandResult::OUTCOME_REMOVED, new DateTimeImmutable()));

        self::assertSame([
            $prefix . 'ingest/v1/' . $project . '/messenger/commands/claim',
            $prefix . $project . '/messenger/commands/claim',
            $prefix . $project . '/messenger/commands/' . RemoteRetryFixtures::command()->id . '/result',
        ], $this->urls());
    }

    #[DataProvider('otherStatuses')]
    public function testRemoteCommandsNeverRetryOtherStatuses(int $status): void
    {
        $this->responses = [$this->answer($status)];

        try {
            $this->remoteClient(new IngestPathResolver())->claim([]);
            self::fail('Expected RemoteCommandException');
        } catch (RemoteCommandException $e) {
            self::assertSame($status, $e->statusCode);
        }

        self::assertCount(1, $this->requests);
        self::assertTrue(str_contains($this->urls()[0], '/api/ingest/v1/'));
    }

    public function testAuditLoL1ResultNotFoundOnBothPathsDoesNotStopClaimsOnAnOlderBeacon(): void
    {
        // LO-L1: against Beacon < 1.147, a result posted while the target is unknown gets a routing 404 on the versioned
        // path and an application 404 `not_found` on the legacy one. That keeps the versioned path, and the next claim
        // self-heals back to the legacy path instead of dropping.
        $resolver        = new IngestPathResolver();
        $client          = $this->remoteClient($resolver);
        $prefix          = 'https://beacon.example.com:9447/api/';
        $project         = RemoteRetryFixtures::PROJECT;
        $command         = RemoteRetryFixtures::command();
        $this->responses = [
            $this->answer(404),
            new MockResponse('{"error":"not_found"}', ['http_code' => 404]),
            $this->answer(404),
            new MockResponse('{"commands":[],"poll_after":15}'),
            new MockResponse('{"commands":[]}'),
        ];

        try {
            $client->postResult($command, CommandResult::done(CommandResult::OUTCOME_REMOVED, new DateTimeImmutable()));
            self::fail('Expected RemoteCommandException');
        } catch (RemoteCommandException $e) {
            self::assertSame(404, $e->statusCode);
        }
        self::assertSame(15, $client->claim([])->pollAfter);
        $client->claim([]);

        self::assertSame([
            $prefix . 'ingest/v1/' . $project . '/messenger/commands/' . $command->id . '/result',
            $prefix . $project . '/messenger/commands/' . $command->id . '/result',
            $prefix . 'ingest/v1/' . $project . '/messenger/commands/claim',
            $prefix . $project . '/messenger/commands/claim',
            $prefix . $project . '/messenger/commands/claim',
        ], $this->urls());
    }

    public function testAsyncTransportKeepsAtMostMaxPendingRequests(): void
    {
        // LO-L3: a long console command in async mode never accumulates responses or bodies without bound.
        $async           = new AsyncEnvelopeTransport($this->transport(new IngestPathResolver()), 2);
        $this->responses = [$this->answer(404), $this->answer(404), $this->answer(202), $this->answer(202), $this->answer(202)];

        self::assertTrue($async->send("one\n"));
        self::assertTrue($async->send("two\n"));
        self::assertTrue($async->send("three\n")); // starts, then completes "one" (with its legacy resend)
        self::assertCount(2, (fn (): array => $this->pending)->call($async));
        self::assertSame([self::VERSIONED, self::VERSIONED, self::VERSIONED, self::LEGACY], $this->urls());

        $async->flush();
        self::assertSame([self::VERSIONED, self::VERSIONED, self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
        self::assertSame(100, constant(AsyncEnvelopeTransport::class . '::DEFAULT_MAX_PENDING'));
    }

    public function testRemoteCommandsLegacyMode(): void
    {
        $this->responses = [new MockResponse('{}')];

        $this->remoteClient(new IngestPathResolver('legacy'))->claim([]);

        self::assertSame(['https://beacon.example.com:9447/api/' . RemoteRetryFixtures::PROJECT . '/messenger/commands/claim'], $this->urls());
    }

    private function transport(?IngestPathResolver $resolver = null, ?ArrayLogger $logger = null): EnvelopeTransport
    {
        return new EnvelopeTransport($this->http(), $this->dsn(), true, 2.0, $logger, 'beacon-bundle/test', $resolver);
    }

    private function remoteClient(IngestPathResolver $resolver, string $dsn = RemoteRetryFixtures::DSN): RemoteCommandClient
    {
        return new RemoteCommandClient($this->http(), RemoteRetryFixtures::credentials($dsn), 'worker-2', 'prod', true, null, 'beacon-bundle/test', $resolver);
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => (string) ($options['body'] ?? '')];
            $next             = array_shift($this->responses);
            if ($next instanceof TransportException) {
                throw $next;
            }

            return $next ?? $this->answer(500);
        });
    }

    private function dsn(): BeaconDsn
    {
        return (new BeaconDsnParser())->parse('https://pk:sk@beacon.example.com:9444/5');
    }

    private function answer(int $status): MockResponse
    {
        return new MockResponse('', ['http_code' => $status]);
    }

    /**
     * @return list<string>
     */
    private function urls(): array
    {
        return array_column($this->requests, 'url');
    }
}
