<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Messenger\RemoteRetry;

use DateTimeImmutable;
use LogicException;
use Nowo\BeaconBundle\Messenger\RemoteRetry\CommandResult;
use Nowo\BeaconBundle\Messenger\RemoteRetry\FailedMessageCommandExecutor;
use Nowo\BeaconBundle\Messenger\RemoteRetry\PollReport;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandPoller;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\InvoiceMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\ScriptedListableTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionProperty;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Worker;

use function is_callable;
use function json_encode;

final class RemoteCommandPollerTest extends TestCase
{
    private float $now = 1000.0;

    /** @var list<callable|MockResponse> */
    private array $responses = [];

    /** @var list<array{url: string, body: string}> */
    private array $requests = [];

    private ArrayLogger $logger;

    private ScriptedListableTransport $failed;

    private ScriptedListableTransport $async;

    protected function setUp(): void
    {
        $this->logger                   = new ArrayLogger();
        $this->failed                   = new ScriptedListableTransport();
        $this->async                    = new ScriptedListableTransport();
        $this->failed->messages['8812'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);
    }

    public function testSubscribesToWorkerEvents(): void
    {
        self::assertSame([
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ], RemoteCommandPoller::getSubscribedEvents());
    }

    public function testClaimsExecutesReportsAndSchedulesByPollAfter(): void
    {
        $this->responses = [
            $this->claimResponse([RemoteRetryFixtures::commandData()], 45),
            new MockResponse('{"state":"done"}'),
        ];
        $poller = $this->poller();

        $poller->onWorkerStarted(new WorkerStartedEvent($this->worker()));
        $poller->onWorkerRunning(new WorkerRunningEvent($this->worker(), true));

        self::assertCount(2, $this->requests);
        self::assertStringEndsWith('/messenger/commands/claim', $this->requests[0]['url']);
        self::assertStringContainsString('"failure_transports":[{"name":"failed","listable":true}]', $this->requests[0]['body']);
        self::assertStringContainsString('"status":"done","outcome":"requeued"', $this->requests[1]['body']);
        self::assertCount(1, $this->async->sent);
        self::assertSame(45.0, $poller->secondsUntilNextPoll());

        // Not due yet: no request.
        $this->now += 44;
        self::assertNull($poller->pollIfDue());
        self::assertCount(2, $this->requests);

        $this->responses = [$this->claimResponse([], null)];
        ++$this->now;
        $report = $poller->pollIfDue();
        self::assertNotNull($report);
        self::assertSame(PollReport::STATUS_OK, $report->status);
        self::assertSame(30.0, $report->nextPollIn, 'poll_interval when Beacon sends no poll_after');
    }

    public function testExponentialBackoffOnErrorsAndResetAfterSuccess(): void
    {
        $poller = $this->poller();
        $delays = [];
        foreach ([500, 502, 500, 500, 500, 500, 500] as $status) {
            $this->responses = [new MockResponse('', ['http_code' => $status])];
            $report          = $poller->poll();
            self::assertSame(PollReport::STATUS_UNAVAILABLE, $report->status);
            $delays[] = (int) $report->nextPollIn;
        }
        self::assertSame([30, 60, 120, 240, 480, 600, 600], $delays);
        self::assertCount(1, $this->logger->messages('warning'), 'one warning per outage, not per attempt');

        $this->responses = [$this->claimResponse([], 30)];
        $poller->poll();
        $this->responses = [static fn () => throw new TransportException('down')];
        self::assertSame(30.0, $poller->poll()->nextPollIn);
    }

    public function testRetryAfterIsHonoured(): void
    {
        $poller = $this->poller();

        $this->responses = [new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 300']])];
        $report          = $poller->poll();
        self::assertSame(PollReport::STATUS_RATE_LIMITED, $report->status);
        self::assertSame(300.0, $report->nextPollIn);

        $this->responses = [new MockResponse('', ['http_code' => 503])];
        self::assertSame(30.0, $poller->poll()->nextPollIn, 'no Retry-After: backoff');
    }

    public function testAuthErrorsPauseForOneHourWithOneWarning(): void
    {
        $poller          = $this->poller();
        $this->responses = [new MockResponse('', ['http_code' => 404])];

        $report = $poller->poll();
        self::assertSame(PollReport::STATUS_AUTH_ERROR, $report->status);
        self::assertSame(3600.0, $report->nextPollIn);

        $this->now += 1800;
        $paused = $poller->poll();
        self::assertSame(PollReport::STATUS_PAUSED, $paused->status);
        self::assertSame(1800.0, $paused->nextPollIn);
        self::assertCount(1, $this->requests);
        self::assertCount(1, $this->logger->messages('warning'));

        $this->now += 1800;
        $this->responses = [$this->claimResponse([], 30)];
        self::assertSame(PollReport::STATUS_OK, $poller->poll()->status);

        // A worker restart forgets the pause.
        $this->responses = [new MockResponse('', ['http_code' => 401])];
        $poller->poll();
        $poller->onWorkerStarted(new WorkerStartedEvent($this->worker()));
        self::assertSame(0.0, $poller->secondsUntilNextPoll());
        $this->responses = [$this->claimResponse([], 30)];
        self::assertSame(PollReport::STATUS_OK, $poller->poll()->status);
    }

    public function testDisabledCredentialsLogOnceAndNeverCallBeacon(): void
    {
        $poller = $this->poller(RemoteRetryFixtures::credentials(key: ''));

        self::assertSame(PollReport::STATUS_DISABLED, $poller->poll()->status);
        self::assertSame(PollReport::STATUS_DISABLED, $poller->poll()->status);
        self::assertSame([], $this->requests);
        self::assertCount(1, $this->logger->messages('warning'));

        $poller->onWorkerStopped(new WorkerStoppedEvent($this->worker()));
    }

    public function testUnsentResultsAreRetriedBeforeTheNextClaim(): void
    {
        $poller          = $this->poller();
        $this->responses = [
            $this->claimResponse([RemoteRetryFixtures::commandData()], 30),
            new MockResponse('', ['http_code' => 502]),
        ];
        $report = $poller->poll();
        self::assertFalse($report->commands[0]['reported']);

        // Next poll: result still failing → claim skipped, backoff.
        $this->responses = [new MockResponse('', ['http_code' => 503, 'response_headers' => ['Retry-After: 60']])];
        self::assertSame(PollReport::STATUS_RATE_LIMITED, $poller->poll()->status);

        // Then the result goes through and the claim runs.
        $this->responses = [new MockResponse('{}'), $this->claimResponse([], 30)];
        self::assertSame(PollReport::STATUS_OK, $poller->poll()->status);
        self::assertStringEndsWith('/result', $this->requests[3]['url']);
        self::assertStringEndsWith('/claim', $this->requests[4]['url']);
        self::assertCount(1, $this->async->sent, 'the command ran once');
    }

    public function testRejectedResultsAreDroppedAndAuthOnResultPauses(): void
    {
        $poller          = $this->poller();
        $this->responses = [
            $this->claimResponse([RemoteRetryFixtures::commandData()], 30),
            new MockResponse('', ['http_code' => 409]),
        ];
        $report = $poller->poll();
        self::assertFalse($report->commands[0]['reported']);
        self::assertStringContainsString('refused the result', $this->logger->messages('warning')[0]);

        $this->responses = [
            $this->claimResponse([RemoteRetryFixtures::commandData(['id' => 'second'])], 30),
            new MockResponse('', ['http_code' => 401]),
        ];
        $this->now += 30;
        $poller->poll();
        $this->now += 30;
        self::assertSame(PollReport::STATUS_PAUSED, $poller->poll()->status);

        // Worker stop: one last attempt to post the pending result.
        $this->responses = [new MockResponse('{}')];
        $poller->onWorkerStopped(new WorkerStoppedEvent($this->worker()));
        self::assertStringEndsWith('/second/result', $this->requests[4]['url']);

        // A stop while Beacon is unreachable never throws.
        $this->responses = [
            $this->claimResponse([RemoteRetryFixtures::commandData(['id' => 'third'])], 30),
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
        ];
        $poller->poll();
        $poller->onWorkerStopped(new WorkerStoppedEvent($this->worker()));
        self::assertStringEndsWith('/third/result', $this->requests[7]['url']);
    }

    public function testPendingResultIsDroppedAfterMaxAttempts(): void
    {
        // Max attempts: a result that keeps failing is eventually dropped.
        $poller                      = $this->poller();
        $this->failed->messages['z'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);
        $this->responses             = [$this->claimResponse([RemoteRetryFixtures::commandData(['id' => 'z1', 'message_id' => 'z'])], 30), new MockResponse('', ['http_code' => 500])];
        $poller->poll();
        for ($i = 1; $i < RemoteCommandPoller::MAX_RESULT_ATTEMPTS; ++$i) {
            $this->responses = [new MockResponse('', ['http_code' => 500])];
            $poller->poll();
        }
        $this->responses = [new MockResponse('', ['http_code' => 500]), $this->claimResponse([], 30)];
        self::assertSame(PollReport::STATUS_OK, $poller->poll()->status);
        self::assertStringContainsString('result dropped', implode("\n", $this->logger->messages('warning')));
    }

    public function testTooManyPendingResultsAreDropped(): void
    {
        $poller = $this->poller();
        $ref    = new ReflectionProperty(RemoteCommandPoller::class, 'pendingResults');
        $filler = [];
        for ($i = 0; $i < RemoteCommandPoller::MAX_PENDING_RESULTS; ++$i) {
            $filler[] = ['command' => RemoteRetryFixtures::command(['id' => 'p' . $i]), 'result' => CommandResult::done('removed', new DateTimeImmutable()), 'attempts' => 1];
        }
        $ref->setValue($poller, $filler);
        $keep = new ReflectionMethod(RemoteCommandPoller::class, 'keepPending');
        $keep->invoke($poller, RemoteRetryFixtures::command(['id' => 'overflow']), CommandResult::done('removed', new DateTimeImmutable()), 1);

        self::assertCount(RemoteCommandPoller::MAX_PENDING_RESULTS, $ref->getValue($poller));
        self::assertStringContainsString('too many unsent results', $this->logger->messages('warning')[0]);
    }

    public function testUnexpectedErrorsNeverReachTheWorker(): void
    {
        $this->responses = [$this->claimResponse([RemoteRetryFixtures::commandData()], 30), new MockResponse('{}')];
        $throwingLogger  = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($level === 'info') {
                    throw new LogicException('logger exploded');
                }
            }
        };
        $executor = new FailedMessageCommandExecutor(RemoteRetryFixtures::credentials(), $this->locator(), ['retry'], ['failed'], null, new MockClock(new DateTimeImmutable('2026-10-17T10:03:00Z')), $throwingLogger);
        $poller   = new RemoteCommandPoller($this->client(), $executor, RemoteRetryFixtures::credentials(), 30, null, fn (): float => $this->now);

        $report = $poller->poll();
        self::assertSame(PollReport::STATUS_OK, $report->status);
        self::assertSame('failed (internal_error, LogicException)', $report->commands[0]['result']);

        $brokenDescribe = new FailedMessageCommandExecutor(RemoteRetryFixtures::credentials(), $this->locator(), ['retry'], ['failed']);
        $client         = new RemoteCommandClient(new MockHttpClient(static fn () => throw new LogicException('bug')), RemoteRetryFixtures::credentials(), 'w', 'prod');
        $poller         = new RemoteCommandPoller($client, $brokenDescribe, RemoteRetryFixtures::credentials(), 30, null, fn (): float => $this->now);
        $report         = $poller->poll();
        self::assertSame(PollReport::STATUS_UNAVAILABLE, $report->status);
        self::assertSame(LogicException::class, $report->detail);
    }

    public function testDefaultMonotonicClock(): void
    {
        $poller = new RemoteCommandPoller($this->client(), $this->executor(), RemoteRetryFixtures::credentials(key: ''));
        $poller->poll();

        self::assertGreaterThan(3500.0, $poller->secondsUntilNextPoll());
    }

    /**
     * @param list<array<string, mixed>> $commands
     */
    private function claimResponse(array $commands, ?int $pollAfter): MockResponse
    {
        return new MockResponse((string) json_encode(['commands' => $commands, 'poll_after' => $pollAfter]));
    }

    private function poller(?RemoteRetryCredentials $credentials = null): RemoteCommandPoller
    {
        return new RemoteCommandPoller($this->client(), $this->executor(), $credentials ?? RemoteRetryFixtures::credentials(), 30, $this->logger, fn (): float => $this->now);
    }

    private function client(): RemoteCommandClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => (string) ($options['body'] ?? '')];
            $next             = array_shift($this->responses);
            if ($next === null) {
                throw new LogicException('Unexpected request to ' . $url);
            }

            return is_callable($next) ? $next() : $next;
        });

        return new RemoteCommandClient($http, RemoteRetryFixtures::credentials(), 'worker-2', 'prod', true, null, 'beacon-bundle/1.16.0');
    }

    private function executor(): FailedMessageCommandExecutor
    {
        return new FailedMessageCommandExecutor(
            RemoteRetryFixtures::credentials(),
            $this->locator(),
            ['retry', 'discard'],
            ['failed'],
            null,
            new MockClock(new DateTimeImmutable('2026-10-17T10:03:00Z')),
        );
    }

    /**
     * @return ServiceLocator<mixed>
     */
    private function locator(): ServiceLocator
    {
        $failed = $this->failed;
        $async  = $this->async;

        return new ServiceLocator(['failed' => static fn (): ScriptedListableTransport => $failed, 'async' => static fn (): ScriptedListableTransport => $async]);
    }

    private function worker(): Worker
    {
        return new Worker([], $this->createMock(MessageBusInterface::class));
    }
}
