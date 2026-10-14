<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use LogicException;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessage;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerRetryListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerTransactionListener;
use Nowo\BeaconBundle\EventListener\BeaconWorkerMemoryListener;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

final class BeaconMessengerObservabilityTest extends TestCase
{
    private const HANDLER_A = 'App\\Handler\\SendInvoiceHandler::__invoke';
    private const HANDLER_B = 'App\\Handler\\AuditHandler::__invoke';

    public function testFailedListenerAddsRetryStateAndAllHandlers(): void
    {
        $client   = new RecordingBeaconClient();
        $listener = new BeaconMessengerFailedListener($client);
        $envelope = new Envelope(new stdClass(), [new RedeliveryStamp(3)]);

        $listener(new WorkerMessageFailedEvent($envelope, 'async', $this->handlerFailure($envelope)));
        $listener(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('first try')));

        self::assertCount(2, $client->exceptions);
        $exhausted = $client->exceptions[0]['extra']['messenger'];
        self::assertSame('exhausted', $exhausted['retry_state']);
        self::assertSame(self::HANDLER_A, $exhausted['handler_class']);
        self::assertSame([self::HANDLER_A, self::HANDLER_B], $exhausted['handler_classes']);
        self::assertSame('failed', $client->exceptions[1]['extra']['messenger']['retry_state']);
    }

    public function testRetryListenerReportsWillRetryWithStableFingerprint(): void
    {
        $client   = new RecordingBeaconClient();
        $listener = new BeaconMessengerRetryListener($client, true, false, 1.0, [LogicException::class]);
        $envelope = new Envelope(new stdClass(), [new RedeliveryStamp(1)]);

        $retrying = new WorkerMessageFailedEvent($envelope, 'async', $this->handlerFailure($envelope));
        $retrying->setForRetry();
        $listener->onMessageFailed($retrying);

        self::assertCount(1, $client->messages);
        $sent = $client->messages[0];
        self::assertSame('warning', $sent['level']);
        self::assertSame('will_retry', $sent['extra']['messenger']['retry_state']);
        self::assertSame(2, $sent['extra']['messenger']['attempt']);
        self::assertSame(RuntimeException::class, $sent['extra']['exception']['type']);
        self::assertSame(['beacon.messenger.retry', stdClass::class, self::HANDLER_A, RuntimeException::class], $sent['fingerprint']);
        self::assertStringContainsString('gateway down', $sent['message']);

        // Final failures, ignored exceptions and the recovered path (disabled) send nothing more.
        $listener->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('final')));
        $ignored = new WorkerMessageFailedEvent($envelope, 'async', new LogicException('ignored'));
        $ignored->setForRetry();
        $listener->onMessageFailed($ignored);
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertCount(1, $client->messages);
    }

    public function testRetryListenerReportsRecoveredMessages(): void
    {
        $client   = new RecordingBeaconClient();
        $listener = new BeaconMessengerRetryListener($client, false, true);

        $listener->onMessageHandled(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertSame([], $client->messages, 'first-attempt success is not a recovery');

        $recovered = new Envelope(new stdClass(), [new RedeliveryStamp(2), new HandledStamp(null, self::HANDLER_A)]);
        $listener->onMessageHandled(new WorkerMessageHandledEvent($recovered, 'async'));

        self::assertCount(1, $client->messages);
        self::assertSame('info', $client->messages[0]['level']);
        self::assertSame('recovered', $client->messages[0]['extra']['messenger']['retry_state']);
        self::assertSame(['beacon.messenger.recovered', stdClass::class, self::HANDLER_A], $client->messages[0]['fingerprint']);

        $retrying = new WorkerMessageFailedEvent($recovered, 'async', new RuntimeException('x'));
        $retrying->setForRetry();
        $listener->onMessageFailed($retrying);
        self::assertCount(1, $client->messages, 'report_retries disabled');
    }

    public function testRetryListenerSamplingAndDisabledClient(): void
    {
        $envelope = new Envelope(new stdClass(), [new RedeliveryStamp(1)]);
        $retrying = new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('x'));
        $retrying->setForRetry();

        $dropped = new RecordingBeaconClient();
        $sampler = new BeaconMessengerRetryListener($dropped, true, true, 0.5, [], null, static fn (): float => 0.9);
        $sampler->onMessageFailed($retrying);
        $sampler->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertSame([], $dropped->messages);

        $kept = new RecordingBeaconClient();
        (new BeaconMessengerRetryListener($kept, true, false, 0.5, [], null, static fn (): float => 0.1))->onMessageFailed($retrying);
        self::assertCount(1, $kept->messages);

        $disabled = new RecordingBeaconClient(false);
        $listener = new BeaconMessengerRetryListener($disabled, true, true);
        $listener->onMessageFailed($retrying);
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertSame([], $disabled->messages);

        self::assertArrayHasKey(WorkerMessageHandledEvent::class, BeaconMessengerRetryListener::getSubscribedEvents());
    }

    public function testTransactionListenerSendsOneTransactionPerMessage(): void
    {
        $client  = new RecordingBeaconClient();
        $buffer  = new SpanBuffer();
        $tx      = new BeaconMessengerTransactionListener($client, $buffer);
        $handled = new Envelope(new stdClass(), [new HandledStamp(null, self::HANDLER_A)]);

        $buffer->add('db.sql.query', 'stale span from idle time', 1.0, 2.0);
        $tx->onMessageReceived(new WorkerMessageReceivedEvent($handled, 'async'));
        self::assertSame([], $buffer->all(), 'stale spans are dropped on receive');

        $tx->onMessageHandled(new WorkerMessageHandledEvent($handled, 'async'));

        self::assertCount(1, $client->transactions);
        $sent = $client->transactions[0];
        self::assertSame(stdClass::class, $sent['name']);
        self::assertSame('queue.process', $sent['extra']['messenger']['op']);
        self::assertSame('ok', $sent['extra']['messenger']['status']);
        self::assertSame(self::HANDLER_A, $sent['extra']['messenger']['handler_class']);
        self::assertGreaterThanOrEqual($sent['start'], $sent['end']);

        // A handled event without a matching receive (e.g. not sampled) sends nothing.
        $tx->onMessageHandled(new WorkerMessageHandledEvent($handled, 'async'));
        self::assertCount(1, $client->transactions);
    }

    public function testTransactionListenerFailureStatuses(): void
    {
        $client   = new RecordingBeaconClient();
        $tx       = new BeaconMessengerTransactionListener($client);
        $envelope = new Envelope(new stdClass());

        $tx->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $retrying = new WorkerMessageFailedEvent($envelope, 'async', $this->handlerFailure($envelope));
        $retrying->setForRetry();
        $tx->onMessageFailed($retrying);

        $tx->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $tx->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('final')));

        self::assertSame('retry', $client->transactions[0]['extra']['messenger']['status']);
        self::assertSame(self::HANDLER_A, $client->transactions[0]['extra']['messenger']['handler_class']);
        self::assertSame('failed', $client->transactions[1]['extra']['messenger']['status']);
    }

    public function testTransactionListenerSkipsUnhandledUnsampledAndDisabled(): void
    {
        $envelope = new Envelope(new stdClass());

        $client = new RecordingBeaconClient();
        $tx     = new BeaconMessengerTransactionListener($client, null, 0.5, null, static fn (): float => 0.9);
        $tx->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $tx->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $skipped = new WorkerMessageReceivedEvent($envelope, 'async');
        $skipped->shouldHandle(false);
        $kept = new BeaconMessengerTransactionListener($client, null, 0.5, null, static fn (): float => 0.1);
        $kept->onMessageReceived($skipped);
        $kept->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $disabled = new BeaconMessengerTransactionListener(new RecordingBeaconClient(false));
        $disabled->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        self::assertSame([], $client->transactions);

        $kept->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $kept->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertCount(1, $client->transactions);

        self::assertSame(['onMessageFailed', -1024], BeaconMessengerTransactionListener::getSubscribedEvents()[WorkerMessageFailedEvent::class]);
    }

    /**
     * Reporting on Beacon's own queued send would queue another send (endless loop under
     * `transport.mode: messenger`); configured host classes are skipped the same way.
     */
    public function testListenersSkipOwnEnvelopeSendAndIgnoredMessageClasses(): void
    {
        foreach ([new Envelope(new SendBeaconEnvelopeMessage('{}'), [new RedeliveryStamp(1)]), new Envelope(new stdClass(), [new RedeliveryStamp(1)])] as $envelope) {
            $client  = new RecordingBeaconClient();
            $ignored = [stdClass::class];

            $tx = new BeaconMessengerTransactionListener($client, null, 1.0, null, null, $ignored);
            $tx->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'beacon_self'));
            $tx->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'beacon_self'));

            $retry    = new BeaconMessengerRetryListener($client, true, true, 1.0, [], null, null, $ignored);
            $retrying = new WorkerMessageFailedEvent($envelope, 'beacon_self', new RuntimeException('ingest down'));
            $retrying->setForRetry();
            $retry->onMessageFailed($retrying);
            $retry->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'beacon_self'));

            (new BeaconMessengerFailedListener($client, true, [], true, null, $ignored))(
                new WorkerMessageFailedEvent($envelope, 'beacon_self', new RuntimeException('ingest down')),
            );

            self::assertSame([], $client->transactions);
            self::assertSame([], $client->messages);
            self::assertSame([], $client->exceptions);
        }

        // Without the host list, Beacon's own send is still skipped by the failure listener.
        $client = new RecordingBeaconClient();
        (new BeaconMessengerFailedListener($client))(
            new WorkerMessageFailedEvent(new Envelope(new SendBeaconEnvelopeMessage('{}')), 'beacon_self', new RuntimeException('x')),
        );
        self::assertSame([], $client->exceptions);
    }

    public function testWorkerMemoryListenerWarnsOncePerWorker(): void
    {
        $client = new RecordingBeaconClient();
        $usage  = 100;
        $memory = new BeaconWorkerMemoryListener($client, 0.8, static function () use (&$usage): int {
            return $usage;
        }, static fn (): int => 1000);

        $worker = $this->worker(['async', 'async_notify']);
        $memory->onWorkerStarted(new WorkerStartedEvent($worker));
        $memory->onWorkerRunning(new WorkerRunningEvent($worker, true));
        self::assertSame([], $client->messages);

        $usage = 850;
        $memory->onWorkerRunning(new WorkerRunningEvent($worker, false));
        $memory->onWorkerRunning(new WorkerRunningEvent($worker, false));

        self::assertCount(1, $client->messages);
        self::assertSame('warning', $client->messages[0]['level']);
        self::assertStringContainsString('85%', $client->messages[0]['message']);
        self::assertSame(['async', 'async_notify'], $client->messages[0]['extra']['messenger_worker']['transports']);
        self::assertSame(['beacon.messenger.worker_memory', 'async,async_notify'], $client->messages[0]['fingerprint']);

        // A new worker run re-arms the warning.
        $memory->onWorkerStarted(new WorkerStartedEvent($this->worker([])));
        $memory->onWorkerRunning(new WorkerRunningEvent($worker, false));
        self::assertCount(2, $client->messages);
        self::assertStringContainsString('unknown transports', $client->messages[1]['message']);
    }

    public function testWorkerMemoryListenerSkipsUnlimitedAndDisabled(): void
    {
        $worker    = $this->worker(['async']);
        $unlimited = new RecordingBeaconClient();
        (new BeaconWorkerMemoryListener($unlimited, 0.8, static fn (): int => 999, static fn (): int => -1))
            ->onWorkerRunning(new WorkerRunningEvent($worker, false));
        self::assertSame([], $unlimited->messages);

        $disabled = new RecordingBeaconClient(false);
        (new BeaconWorkerMemoryListener($disabled, 0.8, static fn (): int => 999, static fn (): int => 1000))
            ->onWorkerRunning(new WorkerRunningEvent($worker, false));
        self::assertSame([], $disabled->messages);

        self::assertArrayHasKey(WorkerStartedEvent::class, BeaconWorkerMemoryListener::getSubscribedEvents());
    }

    public function testParseBytes(): void
    {
        self::assertSame(-1, BeaconWorkerMemoryListener::parseBytes(false));
        self::assertSame(-1, BeaconWorkerMemoryListener::parseBytes('-1'));
        self::assertSame(-1, BeaconWorkerMemoryListener::parseBytes(' '));
        self::assertSame(1024, BeaconWorkerMemoryListener::parseBytes('1K'));
        self::assertSame(512 * 1024 * 1024, BeaconWorkerMemoryListener::parseBytes('512M'));
        self::assertSame(2 * 1024 * 1024 * 1024, BeaconWorkerMemoryListener::parseBytes('2g'));
        self::assertSame(1000, BeaconWorkerMemoryListener::parseBytes('1000'));
    }

    private function handlerFailure(Envelope $envelope): HandlerFailedException
    {
        return new HandlerFailedException($envelope, [
            self::HANDLER_A => new RuntimeException('gateway down'),
            self::HANDLER_B => new RuntimeException('audit down'),
        ]);
    }

    /**
     * @param list<string> $transports
     */
    private function worker(array $transports): Worker
    {
        $receivers = [];
        foreach ($transports as $name) {
            $receivers[$name] = $this->createStub(ReceiverInterface::class);
        }

        return new Worker($receivers, $this->createStub(MessageBusInterface::class));
    }
}
