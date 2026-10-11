<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use DateTimeInterface;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Messenger\MessengerContext;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\Messenger\Envelope;
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
 * {@see ScheduledStamp}, optional `extra.scheduler` context is attached.
 * Message bodies are never attached (PII / secrets risk).
 */
final class BeaconMessengerFailedListener
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        /** @var list<class-string> */
        private readonly array $ignoreExceptions = [],
        private readonly bool $includeSchedulerContext = true,
        private readonly ?TraceIdProvider $traceIdProvider = null,
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

        if ($event->willRetry()) {
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

        $extra = [
            'messenger' => $messenger,
        ];

        $scheduler = $this->schedulerExtra($envelope);
        if ($scheduler !== null) {
            $extra['scheduler'] = $scheduler;
        }

        $this->client->captureException($throwable, $extra);
    }

    /**
     * @return array{schedule_name: string, recurring_id: string, triggered_at: string, trigger: string}|null
     */
    private function schedulerExtra(Envelope $envelope): ?array
    {
        if (!$this->includeSchedulerContext || !class_exists(ScheduledStamp::class)) {
            return null;
        }

        $stamp = $envelope->last(ScheduledStamp::class);
        if (!$stamp instanceof ScheduledStamp) {
            return null;
        }

        $context = $stamp->messageContext;

        return [
            'schedule_name' => $context->name,
            'recurring_id'  => $context->id,
            'triggered_at'  => $context->triggeredAt->format(DateTimeInterface::ATOM),
            'trigger'       => (string) $context->trigger,
        ];
    }
}
