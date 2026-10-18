<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\FailureTransport;

use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function is_int;
use function strlen;

/**
 * Per-message holder of the id a failed message got in its failure transport.
 *
 * {@see RecordingFailureSender} writes it while Symfony's `SendFailedMessageToFailureTransportListener`
 * (priority -100) stores the envelope; {@see BeaconMessengerFailedListener} (priority -150) takes it
 * for `extra.messenger.failure_transport` / `failure_message_id`. Cleared on every
 * {@see WorkerMessageReceivedEvent} and by `kernel.reset`, so a value never leaks to another message.
 */
final class FailureTransportRecorder implements EventSubscriberInterface, ResetInterface
{
    /** Longest failure message id sent to Beacon (longer ids are not recorded). */
    public const MAX_ID_LENGTH = 255;

    /** @var array{receiver: string, failure_transport: string, failure_message_id: string}|null */
    private ?array $recorded = null;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onMessageReceived', 1024],
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->reset();
    }

    /**
     * Remember the failure-transport id of the message that failed on `$originalReceiver`.
     */
    public function record(string $originalReceiver, string $failureTransport, string|int $messageId): void
    {
        $id = is_int($messageId) ? (string) $messageId : $messageId;
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH || $failureTransport === '') {
            return;
        }

        // @igor-ignore - Per-message holder, cleared on WorkerMessageReceivedEvent and kernel.reset.
        $this->recorded = [
            'receiver'           => $originalReceiver,
            'failure_transport'  => $failureTransport,
            'failure_message_id' => $id,
        ];
    }

    /**
     * Ids recorded for a message received on `$receiverName` (consumed: a second call returns null).
     *
     * @return array{failure_transport: string, failure_message_id: string}|null
     */
    public function take(string $receiverName): ?array
    {
        $recorded = $this->recorded;
        $this->reset();

        if ($recorded === null || $recorded['receiver'] !== $receiverName) {
            return null;
        }

        return [
            'failure_transport'  => $recorded['failure_transport'],
            'failure_message_id' => $recorded['failure_message_id'],
        ];
    }

    public function reset(): void
    {
        // @igor-ignore - Per-message holder reset.
        $this->recorded = null;
    }
}
