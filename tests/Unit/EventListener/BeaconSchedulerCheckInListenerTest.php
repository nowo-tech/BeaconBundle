<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use DateTimeImmutable;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\FlushableEnvelopeTransportInterface;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconSchedulerCheckInListener;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Nowo\BeaconBundle\Scheduler\ScheduledRun;
use Nowo\BeaconBundle\Scheduler\SchedulerRunRegistry;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\FixedScheduleProvider;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\OptedOutMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\PlainMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\SlowSyncMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\ThrowingCheckInClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class BeaconSchedulerCheckInListenerTest extends TestCase
{
    public function testSubscribesAtDocumentedPriorities(): void
    {
        $events = BeaconSchedulerCheckInListener::getSubscribedEvents();

        self::assertSame(['onMessageReceived', -512], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageHandled', -512], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFailed', -512], $events[WorkerMessageFailedEvent::class]);
        self::assertSame('onWorkerStarted', $events[WorkerStartedEvent::class]);
        self::assertSame('onWorkerRunning', $events[WorkerRunningEvent::class]);
        self::assertSame('onWorkerStopped', $events[WorkerStoppedEvent::class]);
    }

    public function testCatalogOnStartRefreshAndStop(): void
    {
        $clock    = new MockClock('2026-10-17T10:00:00+00:00');
        $client   = new RecordingBeaconClient();
        $flushes  = $this->flushCounter();
        $listener = $this->listener($client, $clock, $flushes['registry'], refreshHours: 2);

        $worker = $this->worker(['scheduler_default', 'scheduler_reports', 'scheduler_default', 'scheduler_unknown', 'async']);
        $listener->onWorkerStarted(new WorkerStartedEvent($worker));

        self::assertCount(1, $client->catalogs);
        self::assertSame(['default', 'reports'], array_column($client->catalogs[0], 'name'));
        self::assertSame(1, $flushes['transport']->count);

        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
        $clock->sleep(7199);
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
        self::assertCount(1, $client->catalogs);

        $clock->sleep(1);
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, false));
        self::assertCount(2, $client->catalogs, 'refreshed after catalog_refresh_hours');

        $listener->onWorkerStopped(new WorkerStoppedEvent($worker));
        $clock->sleep(86400);
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
        self::assertCount(2, $client->catalogs);

        // A worker without scheduler transports sends nothing.
        $listener->onWorkerStarted(new WorkerStartedEvent($this->worker(['async'])));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
        self::assertCount(2, $client->catalogs);
    }

    public function testCheckInLifecycleOkAndError(): void
    {
        $clock    = new MockClock('2026-10-17T01:00:05+00:00');
        $client   = new RecordingBeaconClient();
        $runs     = new SchedulerRunRegistry();
        $flushes  = $this->flushCounter();
        $listener = $this->listener($client, $clock, $flushes['registry'], runs: $runs);

        $envelope = $this->scheduled(new PlainMessage('s3cr3t'));
        $runId    = ScheduledRun::runId($this->context());

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        self::assertSame(1, $flushes['transport']->count, 'in_progress is flushed right away (async mode)');
        $clock->sleep(301.4);
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));

        self::assertCount(2, $client->checkIns);
        self::assertSame([
            'run_id'       => $runId,
            'schedule'     => 'default',
            'recurring_id' => 'a1b2c3d4',
            'status'       => 'in_progress',
            'triggered_at' => '2026-10-17T01:00:00+00:00',
            'started_at'   => 1792198805.0,
            'next_run_at'  => '2026-10-18T01:00:00+00:00',
        ], $client->checkIns[0]);
        self::assertSame([
            'run_id'           => $runId,
            'schedule'         => 'default',
            'recurring_id'     => 'a1b2c3d4',
            'status'           => 'ok',
            'triggered_at'     => '2026-10-17T01:00:00+00:00',
            'started_at'       => 1792198805.0,
            'finished_at'      => 1792199106.4,
            'duration_ms'      => 301400,
            'next_run_at'      => '2026-10-18T01:00:00+00:00',
            'failure_event_id' => null,
        ], $client->checkIns[1]);
        self::assertStringNotContainsString('s3cr3t', (string) json_encode($client->checkIns));

        // error: the failure listener (priority 0) remembers its event id first.
        $failed = new BeaconMessengerFailedListener($client, checkInRuns: $runs);
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        $failure = new WorkerMessageFailedEvent($envelope, 'scheduler_default', new RuntimeException('boom'));
        $failed($failure);
        $listener->onMessageFailed($failure);

        $error = $this->checkIn($client, 3);
        self::assertSame('error', $error['status']);
        self::assertSame('exception-id', $error['failure_event_id']);
        self::assertSame(0, $error['duration_ms']);
        self::assertSame($runId, $client->exceptions[0]['extra']['scheduler']['run_id']);
        self::assertSame(0, $runs->runningCount());

        // Finish without a known start (e.g. evicted): started_at / duration_ms null.
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));
        self::assertNull($this->checkIn($client, 4)['started_at']);
        self::assertNull($this->checkIn($client, 4)['duration_ms']);
    }

    public function testSkipsUnscheduledCancelledIgnoredAndDisabled(): void
    {
        $clock    = new MockClock('2026-10-17T01:00:05+00:00');
        $client   = new RecordingBeaconClient();
        $listener = $this->listener($client, $clock, policy: new CheckInPolicy([], [stdClass::class]));

        $listener->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope(new PlainMessage()), 'async'));
        $cancelled = new WorkerMessageReceivedEvent($this->scheduled(new PlainMessage()), 'scheduler_default');
        $cancelled->shouldHandle(false);
        $listener->onMessageReceived($cancelled);
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->scheduled(new OptedOutMessage()), 'scheduler_default'));
        $listener->onMessageHandled(new WorkerMessageHandledEvent($this->scheduled(new stdClass()), 'scheduler_default'));
        self::assertSame([], $client->checkIns);

        $disabled = new RecordingBeaconClient(false);
        $off      = $this->listener($disabled, $clock);
        $off->onWorkerStarted(new WorkerStartedEvent($this->worker(['scheduler_default'])));
        $off->onMessageReceived(new WorkerMessageReceivedEvent($this->scheduled(new SlowSyncMessage()), 'scheduler_default'));
        self::assertSame([], $disabled->catalogs);
        self::assertSame([], $disabled->checkIns);

        // A client without the capability is ignored too.
        $plain = $this->createMock(BeaconClientInterface::class);
        $plain->method('isEnabled')->willReturn(true);
        $none = $this->listener($plain, $clock);
        $none->onWorkerStarted(new WorkerStartedEvent($this->worker(['scheduler_default'])));
        $none->onMessageReceived(new WorkerMessageReceivedEvent($this->scheduled(new SlowSyncMessage()), 'scheduler_default'));
        $this->addToAssertionCount(1);
    }

    public function testBeaconOutageNeverThrowsIntoTheWorker(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };

        $throwing = new ThrowingCheckInClient();
        $registry = new PendingTransportRegistry();
        $registry->register(new class implements FlushableEnvelopeTransportInterface {
            public function send(string $envelopeBody): bool
            {
                return true;
            }

            public function getDsn(): BeaconDsn
            {
                return (new BeaconDsnParser())->parse('https://k:s@localhost/1');
            }

            public function flush(): void
            {
                throw new RuntimeException('network down');
            }
        });

        $listener = new BeaconSchedulerCheckInListener(
            $throwing,
            $this->catalogBuilder(),
            new CheckInPolicy(),
            new SchedulerRunRegistry(),
            24,
            $registry,
            new MockClock('2026-10-17T01:00:05+00:00'),
            $logger,
        );

        $listener->onWorkerStarted(new WorkerStartedEvent($this->worker(['scheduler_default'])));
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->scheduled(new PlainMessage()), 'scheduler_default'));

        self::assertCount(4, $logger->records);
        self::assertStringContainsString('schedule catalog not sent', $logger->records[0]);
        self::assertStringContainsString('flushing pending envelopes failed', $logger->records[1]);
        self::assertStringContainsString('check-in not sent', $logger->records[2]);
    }

    public function testDefaultClockAndNoNextRun(): void
    {
        $client   = new RecordingBeaconClient();
        $listener = new BeaconSchedulerCheckInListener($client, $this->catalogBuilder(), new CheckInPolicy(), new SchedulerRunRegistry());
        $context  = new MessageContext('default', 'x', new PeriodicalTrigger(60), new DateTimeImmutable('2026-10-17T01:00:00+00:00'));

        $listener->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope(new PlainMessage(), [new ScheduledStamp($context)]), 'scheduler_default'));

        self::assertNull($client->checkIns[0]['next_run_at']);
        self::assertGreaterThan(1792198800.0, $client->checkIns[0]['started_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkIn(RecordingBeaconClient $client, int $index): array
    {
        return $client->checkIns[$index];
    }

    private function context(): MessageContext
    {
        return new MessageContext(
            'default',
            'a1b2c3d4',
            new PeriodicalTrigger(86400),
            new DateTimeImmutable('2026-10-17T03:00:00+02:00'),
            new DateTimeImmutable('2026-10-18T03:00:00+02:00'),
        );
    }

    private function scheduled(object $message): Envelope
    {
        return new Envelope($message, [new ScheduledStamp($this->context())]);
    }

    /**
     * @param list<string> $transports
     */
    private function worker(array $transports): Worker
    {
        $receiver  = $this->createMock(ReceiverInterface::class);
        $receivers = [];
        foreach ($transports as $name) {
            $receivers[$name] = $receiver;
        }

        return new Worker($receivers, $this->createMock(MessageBusInterface::class));
    }

    private function catalogBuilder(): ScheduleCatalogBuilder
    {
        $schedule = static fn (): FixedScheduleProvider => new FixedScheduleProvider((new Schedule())->add(RecurringMessage::every(60, new PlainMessage())));

        return new ScheduleCatalogBuilder(
            new CheckInPolicy(),
            new ServiceLocator(['default' => $schedule, 'reports' => $schedule]),
            new MockClock('2026-10-17T10:00:00+00:00'),
        );
    }

    private function listener(
        BeaconClientInterface $client,
        MockClock $clock,
        ?PendingTransportRegistry $pending = null,
        int $refreshHours = 24,
        ?SchedulerRunRegistry $runs = null,
        ?CheckInPolicy $policy = null,
    ): BeaconSchedulerCheckInListener {
        return new BeaconSchedulerCheckInListener(
            $client,
            $this->catalogBuilder(),
            $policy ?? new CheckInPolicy(),
            $runs ?? new SchedulerRunRegistry(),
            $refreshHours,
            $pending,
            $clock,
        );
    }

    /**
     * @return array{registry: PendingTransportRegistry, transport: object{count: int}}
     */
    private function flushCounter(): array
    {
        $transport = new class implements FlushableEnvelopeTransportInterface {
            public int $count = 0;

            public function send(string $envelopeBody): bool
            {
                return true;
            }

            public function getDsn(): BeaconDsn
            {
                return (new BeaconDsnParser())->parse('https://k:s@localhost/1');
            }

            public function flush(): void
            {
                ++$this->count;
            }
        };
        $registry = new PendingTransportRegistry();
        $registry->register($transport);

        return ['registry' => $registry, 'transport' => $transport];
    }
}
