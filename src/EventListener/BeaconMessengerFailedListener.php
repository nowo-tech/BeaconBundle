<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use DateTimeInterface;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Messenger\FailureTransport\FailureTransportRecorder;
use Nowo\BeaconBundle\Messenger\MessengerContext;
use Nowo\BeaconBundle\Scheduler\ScheduledRun;
use Nowo\BeaconBundle\Scheduler\SchedulerRunRegistry;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;

use function class_exists;

/**
 * Reports Messenger worker failures that will not be retried.
 *
 * `extra.messenger.retry_state` is `exhausted` after at least one retry, otherwise `failed`.
 * When several handlers fail, `handler_classes` lists all of them (`handler_class` stays the first).
 *
 * When `symfony/scheduler` is installed and the envelope carries a
 * {@see ScheduledStamp}, optional `extra.scheduler` context is attached (including the check-in
 * `run_id`); with Scheduler check-ins enabled the event id is remembered so the `error` check-in links it.
 * Message bodies are never attached (PII / secrets risk). Beacon's own Envelope send and
 * `messenger.ignore_message_classes` are skipped ({@see MessengerContext::isIgnoredMessage()}).
 *
 * When the message was stored in a failure transport that can find messages by id (Doctrine, Redis),
 * `failure_transport` and `failure_message_id` are added ({@see FailureTransportRecorder}) so Beacon
 * can retry / discard it remotely.
 */
final class BeaconMessengerFailedListener
{
    /**
     * After Symfony's `SendFailedMessageToFailureTransportListener` (-100), so the failure-transport id
     * is known; before the Scheduler check-in (-512) and transaction (-1024) listeners.
     */
    public const PRIORITY = -150;

    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        /** @var list<class-string> */
        private readonly array $ignoreExceptions = [],
        private readonly bool $includeSchedulerContext = true,
        private readonly ?TraceIdProvider $traceIdProvider = null,
        /** @var list<class-string> */
        private readonly array $ignoreMessageClasses = [],
        private readonly ?SchedulerRunRegistry $checkInRuns = null,
        private readonly ?FailureTransportRecorder $failureRecorder = null,
    ) {
    }

    /**
     * Capture the failure when Messenger will not retry the message.
     */
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if (!$this->enabled || !$this->client->isEnabled()) {
            return;
        }

        if ($event->willRetry() || MessengerContext::isIgnoredMessage($event->getEnvelope(), $this->ignoreMessageClasses)) {
            return;
        }

        $throwable = $event->getThrowable();
        if (MessengerContext::isIgnored($throwable, $this->ignoreExceptions)) {
            return;
        }

        $envelope = $event->getEnvelope();
        MessengerContext::restoreTrace($envelope, $this->traceIdProvider);

        $messenger = MessengerContext::withHandlers(
            MessengerContext::fromEnvelope($envelope, $event->getReceiverName()),
            MessengerContext::failedHandlers($throwable),
        );
        $messenger['retry_state'] = ((int) $messenger['retry_count']) > 0
            ? MessengerContext::RETRY_STATE_EXHAUSTED
            : MessengerContext::RETRY_STATE_FAILED;

        $failureIds = $this->failureRecorder?->take($event->getReceiverName());
        if ($failureIds !== null) {
            $messenger += $failureIds;
        }

        $extra = [
            'messenger' => $messenger,
        ];

        $stamp = class_exists(ScheduledStamp::class) ? $envelope->last(ScheduledStamp::class) : null;
        if ($stamp instanceof ScheduledStamp && $this->includeSchedulerContext) {
            $extra['scheduler'] = $this->schedulerExtra($stamp);
        }

        $eventId = $this->client->captureException($throwable, $extra);

        if ($stamp instanceof ScheduledStamp && $eventId !== null && $this->checkInRuns instanceof SchedulerRunRegistry) {
            $this->checkInRuns->rememberFailureEvent(ScheduledRun::runId($stamp->messageContext), $eventId);
        }
    }

    /**
     * @return array{schedule_name: string, recurring_id: string, triggered_at: string, trigger: string, run_id: string}
     */
    private function schedulerExtra(ScheduledStamp $stamp): array
    {
        $context = $stamp->messageContext;

        return [
            'schedule_name' => $context->name,
            'recurring_id'  => $context->id,
            'triggered_at'  => $context->triggeredAt->format(DateTimeInterface::ATOM),
            'trigger'       => (string) $context->trigger,
            'run_id'        => ScheduledRun::runId($context),
        ];
    }
}
