<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Messenger;

use DateTimeImmutable;
use LogicException;
use Nowo\BeaconBundle\Messenger\BeaconTraceStamp;
use Nowo\BeaconBundle\Messenger\MessengerContext;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

use function str_repeat;

use const DATE_ATOM;

final class MessengerContextTest extends TestCase
{
    public function testFromEnvelopeReadsIdentifiersOnly(): void
    {
        $first    = new DateTimeImmutable('2026-10-11T08:00:00+00:00');
        $envelope = new Envelope(new stdClass(), [
            new BusNameStamp('messenger.bus.default'),
            new TransportMessageIdStamp('42'),
            new ReceivedStamp('async'),
            new RedeliveryStamp(1, $first),
            new RedeliveryStamp(2),
        ]);

        $context = MessengerContext::fromEnvelope($envelope, 'async');

        self::assertSame(stdClass::class, $context['message_class']);
        self::assertSame(2, $context['retry_count']);
        self::assertSame('async', $context['receiver_name']);
        self::assertSame('messenger.bus.default', $context['bus']);
        self::assertSame('42', $context['transport_message_id']);
        self::assertSame('async', $context['transport']);
        self::assertSame($first->format(DATE_ATOM), $context['first_failure_at']);
    }

    public function testFromEnvelopeMinimal(): void
    {
        $context = MessengerContext::fromEnvelope(new Envelope(new stdClass(), [new TransportMessageIdStamp(1.5)]), '');

        self::assertSame(['message_class' => stdClass::class, 'retry_count' => 0], $context);
    }

    public function testFailedAndHandledHandlers(): void
    {
        $envelope = new Envelope(new stdClass());
        $failure  = new HandlerFailedException($envelope, [
            'App\\Handler\\A::__invoke' => new RuntimeException('a'),
            'App\\Handler\\B::__invoke' => new LogicException('b'),
        ]);

        self::assertSame(['App\\Handler\\A::__invoke', 'App\\Handler\\B::__invoke'], MessengerContext::failedHandlers($failure));
        self::assertSame([], MessengerContext::failedHandlers(new RuntimeException('plain')));
        self::assertSame([], MessengerContext::failedHandlers(new HandlerFailedException($envelope, [new RuntimeException('x')])));

        $handled = $envelope->with(new HandledStamp(null, 'App\\Handler\\A::__invoke'), new HandledStamp(null, ''));
        self::assertSame(['App\\Handler\\A::__invoke'], MessengerContext::handledHandlers($handled));
    }

    public function testWithHandlers(): void
    {
        self::assertSame(['a' => 1], MessengerContext::withHandlers(['a' => 1], []));
        self::assertSame(['handler_class' => 'H1'], MessengerContext::withHandlers([], ['H1']));
        self::assertSame(
            ['handler_class' => 'H1', 'handler_classes' => ['H1', 'H2']],
            MessengerContext::withHandlers([], ['H1', 'H2']),
        );
    }

    public function testRootCauseFollowsPreviousChain(): void
    {
        $root    = new LogicException('root');
        $wrapped = new RuntimeException('outer', 0, new RuntimeException('middle', 0, $root));

        self::assertSame($root, MessengerContext::rootCause($wrapped));
        self::assertSame($root, MessengerContext::rootCause($root));
    }

    public function testRestoreTrace(): void
    {
        $provider = new TraceIdProvider();
        MessengerContext::restoreTrace(new Envelope(new stdClass(), [new BeaconTraceStamp(str_repeat('ab', 16))]), $provider);
        self::assertSame(str_repeat('ab', 16), $provider->get());

        $other = new TraceIdProvider();
        MessengerContext::restoreTrace(new Envelope(new stdClass()), $other);
        self::assertNull($other->get());

        MessengerContext::restoreTrace(new Envelope(new stdClass()), null);
    }

    public function testIsIgnored(): void
    {
        $e = new RuntimeException('x');

        self::assertTrue(MessengerContext::isIgnored($e, [RuntimeException::class]));
        self::assertFalse(MessengerContext::isIgnored($e, [LogicException::class, '', 42]));
        self::assertFalse(MessengerContext::isIgnored($e, []));
    }
}
