<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use DateTimeInterface;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Throwable;

use function class_exists;
use function is_string;

/**
 * Reports Messenger worker failures that will not be retried.
 *
 * When `symfony/scheduler` is installed and the envelope carries a
 * {@see ScheduledStamp}, optional `extra.scheduler` context is attached
 * (schedule name, recurring id, trigger, triggered_at). Message bodies are
 * never attached (PII / secrets risk).
 */
final class BeaconMessengerFailedListener
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        /** @var list<class-string> */
        private readonly array $ignoreExceptions = [],
        private readonly bool $includeSchedulerContext = true,
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
        if ($this->shouldIgnore($throwable)) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message  = $envelope->getMessage();

        $extra = [
            'messenger' => [
                'message_class' => $message::class,
                'receiver_name' => $event->getReceiverName(),
            ],
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
            // TriggerInterface is Stringable (cron expression / label); never the message body.
            'trigger' => (string) $context->trigger,
        ];
    }

    /**
     * Whether `$throwable` matches any configured ignore class.
     */
    private function shouldIgnore(Throwable $throwable): bool
    {
        foreach ($this->ignoreExceptions as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            if ($throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
