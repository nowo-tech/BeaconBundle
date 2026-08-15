<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use DateTimeInterface;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Messenger\BeaconTraceStamp;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Throwable;

use function array_key_first;
use function class_exists;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Reports Messenger worker failures that will not be retried.
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
        if ($this->shouldIgnore($throwable)) {
            return;
        }

        $envelope = $event->getEnvelope();
        $this->restoreTrace($envelope);

        $message = $envelope->getMessage();

        $messenger = [
            'message_class' => $message::class,
            'receiver_name' => $event->getReceiverName(),
            'retry_count'   => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
        ];

        $busStamp = $envelope->last(BusNameStamp::class);
        if ($busStamp instanceof BusNameStamp) {
            $messenger['bus'] = $busStamp->getBusName();
        }

        $transportId = $envelope->last(TransportMessageIdStamp::class);
        if ($transportId instanceof TransportMessageIdStamp) {
            $id = $transportId->getId();
            if (is_string($id) || is_int($id)) {
                $messenger['transport_message_id'] = $id;
            }
        }

        $received = $envelope->last(ReceivedStamp::class);
        if ($received instanceof ReceivedStamp) {
            $messenger['transport'] = $received->getTransportName();
        }

        $handlerClass = $this->handlerClass($throwable);
        if ($handlerClass !== null) {
            $messenger['handler_class'] = $handlerClass;
        }

        $firstFailure = $this->firstFailureAt($envelope);
        if ($firstFailure !== null) {
            $messenger['first_failure_at'] = $firstFailure;
        }

        $extra = [
            'messenger' => $messenger,
        ];

        $scheduler = $this->schedulerExtra($envelope);
        if ($scheduler !== null) {
            $extra['scheduler'] = $scheduler;
        }

        $this->client->captureException($throwable, $extra);
    }

    private function restoreTrace(Envelope $envelope): void
    {
        if (!$this->traceIdProvider instanceof TraceIdProvider) {
            return;
        }

        $stamp = $envelope->last(BeaconTraceStamp::class);
        if ($stamp instanceof BeaconTraceStamp) {
            $this->traceIdProvider->set($stamp->traceId);
        }
    }

    private function handlerClass(Throwable $throwable): ?string
    {
        if ($throwable instanceof HandlerFailedException) {
            foreach ($throwable->getWrappedExceptions() as $handler => $_nested) {
                if (is_string($handler) && $handler !== '' && !is_numeric($handler)) {
                    return $handler;
                }
            }
        }

        return null;
    }

    private function firstFailureAt(Envelope $envelope): ?string
    {
        $stamps = $envelope->all(RedeliveryStamp::class);
        if ($stamps === []) {
            return null;
        }

        $first = $stamps[array_key_first($stamps)] ?? null;
        if (!$first instanceof RedeliveryStamp) {
            return null;
        }

        return $first->getRedeliveredAt()->format(DateTimeInterface::ATOM);
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
