<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Messenger\FailureTransport;

use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\Messenger\FailureTransport\FailureTransportRecorder;
use Nowo\BeaconBundle\Messenger\FailureTransport\RecordingFailureSender;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\DoctrineTransports;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\InvoiceMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\ScriptedListableTransport;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

use function str_repeat;

/**
 * Failure-transport ids end to end: Symfony's failure listener (-100) → RecordingFailureSender →
 * recorder → BeaconMessengerFailedListener (-150).
 */
final class FailureTransportIdsTest extends TestCase
{
    public function testDoctrineFailureTransportIdReachesExtraMessenger(): void
    {
        $doctrine              = new DoctrineTransports();
        [$client, $dispatcher] = $this->worker($doctrine->failed);

        $envelope = $this->received();
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));

        self::assertSame(1, $doctrine->count('failed'));
        $stored = $doctrine->failed->all();
        $id     = null;
        foreach ($stored as $message) {
            $id = (string) $message->last(TransportMessageIdStamp::class)?->getId();
        }

        self::assertCount(1, $client->exceptions);
        $messenger = $client->exceptions[0]['extra']['messenger'];
        self::assertSame('failed', $messenger['failure_transport']);
        self::assertSame($id, $messenger['failure_message_id']);
        self::assertSame('1696', $messenger['transport_message_id']);
        self::assertSame('async', $messenger['transport']);
    }

    public function testNonListableFailureTransportSendsNoIds(): void
    {
        [$client, $dispatcher] = $this->worker(new InMemoryTransport());

        $envelope = $this->received();
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));

        self::assertCount(1, $client->exceptions);
        self::assertArrayNotHasKey('failure_transport', $client->exceptions[0]['extra']['messenger']);
        self::assertArrayNotHasKey('failure_message_id', $client->exceptions[0]['extra']['messenger']);
    }

    public function testRetriedMessageIsNotSentNorRecorded(): void
    {
        $doctrine              = new DoctrineTransports();
        [$client, $dispatcher] = $this->worker($doctrine->failed);

        $event = new WorkerMessageFailedEvent($this->received(), 'async', new RuntimeException('boom'));
        $event->setForRetry();
        $dispatcher->dispatch($event);

        self::assertSame([], $client->exceptions);
    }

    public function testRedisLikeStringIdsAndListableSenderWithoutIdStamp(): void
    {
        $recorder  = new FailureTransportRecorder();
        $transport = new ScriptedListableTransport();
        $sender    = new RecordingFailureSender($transport, 'failed_redis', $recorder);
        $envelope  = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);

        $sender->send($envelope); // no TransportMessageIdStamp returned
        self::assertNull($recorder->take('async'));

        $transport->sendId = '1697712345678-0';
        $sender->send($envelope);
        self::assertSame(['failure_transport' => 'failed_redis', 'failure_message_id' => '1697712345678-0'], $recorder->take('async'));
        self::assertNull($recorder->take('async'), 'take() consumes the value');

        // Without SentToFailureTransportStamp nothing is recorded.
        $sender->send(new Envelope(new InvoiceMessage()));
        self::assertNull($recorder->take('async'));
    }

    public function testRecorderGuardsAndResets(): void
    {
        $recorder = new FailureTransportRecorder();
        self::assertSame([WorkerMessageReceivedEvent::class => ['onMessageReceived', 1024]], FailureTransportRecorder::getSubscribedEvents());

        $recorder->record('async', 'failed', 42);
        self::assertNull($recorder->take('other'), 'ids of another receiver are never attached');

        $recorder->record('async', 'failed', '');
        self::assertNull($recorder->take('async'));
        $recorder->record('async', '', '1');
        self::assertNull($recorder->take('async'));
        $recorder->record('async', 'failed', str_repeat('x', FailureTransportRecorder::MAX_ID_LENGTH + 1));
        self::assertNull($recorder->take('async'));

        $recorder->record('async', 'failed', 42);
        $recorder->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope(new InvoiceMessage()), 'async'));
        self::assertNull($recorder->take('async'));

        $recorder->record('async', 'failed', 42);
        self::assertSame(['failure_transport' => 'failed', 'failure_message_id' => '42'], $recorder->take('async'));
    }

    public function testSenderWithNonScalarIdRecordsNothing(): void
    {
        $recorder          = new FailureTransportRecorder();
        $transport         = new ScriptedListableTransport();
        $transport->sendId = ['not', 'scalar'];

        (new RecordingFailureSender($transport, 'failed', $recorder))->send(new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]));

        self::assertNull($recorder->take('async'));
    }

    /**
     * @return array{RecordingBeaconClient, EventDispatcher}
     */
    private function worker(SenderInterface $failureTransport): array
    {
        $client   = new RecordingBeaconClient();
        $recorder = new FailureTransportRecorder();
        $sender   = new RecordingFailureSender($failureTransport, 'failed', $recorder);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($recorder);
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator(['async' => static fn (): SenderInterface => $sender]),
            null,
            ['async' => 'failed'],
        ));
        $dispatcher->addListener(
            WorkerMessageFailedEvent::class,
            new BeaconMessengerFailedListener($client, true, [], true, null, [], null, $recorder),
            BeaconMessengerFailedListener::PRIORITY,
        );

        return [$client, $dispatcher];
    }

    private function received(): Envelope
    {
        return new Envelope(new InvoiceMessage(), [new ReceivedStamp('async'), new TransportMessageIdStamp('1696')]);
    }
}
