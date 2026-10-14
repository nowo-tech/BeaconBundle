<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger;

use DateTimeInterface;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessage;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Throwable;

use function array_key_first;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Builds the `extra.messenger` context shared by Beacon Messenger listeners.
 *
 * Only identifiers and counters are read from the envelope; the message body is never attached
 * (PII / secrets risk).
 */
final class MessengerContext
{
    /** Final failure on the first attempt (no retry configured or retries disabled). */
    public const RETRY_STATE_FAILED = 'failed';

    /** Final failure after at least one retry. */
    public const RETRY_STATE_EXHAUSTED = 'exhausted';

    /** Failure that Messenger will retry. */
    public const RETRY_STATE_WILL_RETRY = 'will_retry';

    /** Message handled successfully after at least one retry. */
    public const RETRY_STATE_RECOVERED = 'recovered';

    /**
     * Common identifiers for a received envelope.
     *
     * @return array<string, int|string>
     */
    public static function fromEnvelope(Envelope $envelope, ?string $receiverName = null): array
    {
        $context = [
            'message_class' => $envelope->getMessage()::class,
            'retry_count'   => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
        ];

        if ($receiverName !== null && $receiverName !== '') {
            $context['receiver_name'] = $receiverName;
        }

        $busStamp = $envelope->last(BusNameStamp::class);
        if ($busStamp instanceof BusNameStamp) {
            $context['bus'] = $busStamp->getBusName();
        }

        $transportId = $envelope->last(TransportMessageIdStamp::class);
        if ($transportId instanceof TransportMessageIdStamp) {
            $id = $transportId->getId();
            if (is_string($id) || is_int($id)) {
                $context['transport_message_id'] = $id;
            }
        }

        $received = $envelope->last(ReceivedStamp::class);
        if ($received instanceof ReceivedStamp) {
            $context['transport'] = $received->getTransportName();
        }

        $firstFailure = self::firstFailureAt($envelope);
        if ($firstFailure !== null) {
            $context['first_failure_at'] = $firstFailure;
        }

        return $context;
    }

    /**
     * Handler names (`Class::method`) that failed, in Messenger order.
     *
     * @return list<string>
     */
    public static function failedHandlers(Throwable $throwable): array
    {
        if (!$throwable instanceof HandlerFailedException) {
            return [];
        }

        $handlers = [];
        foreach ($throwable->getWrappedExceptions() as $handler => $_nested) {
            if (is_string($handler) && $handler !== '' && !is_numeric($handler)) {
                $handlers[] = $handler;
            }
        }

        return $handlers;
    }

    /**
     * Handler names that handled the envelope successfully ({@see HandledStamp}).
     *
     * @return list<string>
     */
    public static function handledHandlers(Envelope $envelope): array
    {
        $handlers = [];
        foreach ($envelope->all(HandledStamp::class) as $stamp) {
            if ($stamp instanceof HandledStamp && $stamp->getHandlerName() !== '') {
                $handlers[] = $stamp->getHandlerName();
            }
        }

        return $handlers;
    }

    /**
     * Adds `handler_class` (first) and `handler_classes` (all, when more than one) to a context.
     *
     * @param array<string, mixed> $context
     * @param list<string> $handlers
     *
     * @return array<string, mixed>
     */
    public static function withHandlers(array $context, array $handlers): array
    {
        if ($handlers === []) {
            return $context;
        }

        $context['handler_class'] = $handlers[0];
        if (isset($handlers[1])) {
            $context['handler_classes'] = $handlers;
        }

        return $context;
    }

    /**
     * Deepest previous exception (the real cause behind Messenger wrappers).
     */
    public static function rootCause(Throwable $throwable): Throwable
    {
        $current = $throwable;
        while ($current->getPrevious() instanceof Throwable) {
            $current = $current->getPrevious();
        }

        return $current;
    }

    /**
     * Restore the dispatching request's trace id on the worker ({@see BeaconTraceStamp}).
     */
    public static function restoreTrace(Envelope $envelope, ?TraceIdProvider $traceIdProvider): void
    {
        if (!$traceIdProvider instanceof TraceIdProvider) {
            return;
        }

        $stamp = $envelope->last(BeaconTraceStamp::class);
        if ($stamp instanceof BeaconTraceStamp) {
            // @igor-ignore - Not shared worker service state.
            $traceIdProvider->set($stamp->traceId);
        }
    }

    /**
     * Whether `$throwable` matches any configured ignore class (non-string entries are skipped).
     *
     * @param list<mixed> $ignoreExceptions
     */
    public static function isIgnored(Throwable $throwable, array $ignoreExceptions): bool
    {
        foreach ($ignoreExceptions as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            if ($throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether listeners must not report anything about this envelope.
     *
     * Beacon's own queued Envelope send ({@see SendBeaconEnvelopeMessage}) is always skipped: a
     * transaction, retry or failure report about it would queue another send, an endless loop
     * under `transport.mode: messenger`. `$ignoreMessageClasses` adds host message classes
     * (`messenger.ignore_message_classes`; subclasses and interfaces match).
     *
     * @param list<mixed> $ignoreMessageClasses
     */
    public static function isIgnoredMessage(Envelope $envelope, array $ignoreMessageClasses = []): bool
    {
        $message = $envelope->getMessage();
        if ($message instanceof SendBeaconEnvelopeMessage) {
            return true;
        }

        foreach ($ignoreMessageClasses as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            if ($message instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private static function firstFailureAt(Envelope $envelope): ?string
    {
        $stamps = $envelope->all(RedeliveryStamp::class);
        if ($stamps === []) {
            return null;
        }

        $first = $stamps[array_key_first($stamps)] ?? null;
        if (!$first instanceof RedeliveryStamp) { // @codeCoverageIgnore
            return null; // @codeCoverageIgnore
        }

        return $first->getRedeliveredAt()->format(DateTimeInterface::ATOM);
    }
}
