<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\SelfTest;

use DivisionByZeroError;
use InvalidArgumentException;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\FlushableEnvelopeTransportInterface;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTestException;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailHandler;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage;
use Nowo\BeaconBundle\SelfTest\SelfTestResult;
use Nowo\BeaconBundle\SelfTest\SelfTestUnavailableException;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\SentStamp;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

final class BeaconSelfTestTest extends TestCase
{
    private const DSN = 'https://pub:secret@beacon.example.com:9444/42';

    public function testKindsAndHelpers(): void
    {
        self::assertSame([
            'uncaught-exception',
            'php-error',
            'php-warning',
            'captured-exception',
            'captured-message',
            'messenger-failure',
        ], BeaconSelfTest::kinds());
        self::assertTrue(BeaconSelfTest::isUncaught('php-error'));
        self::assertFalse(BeaconSelfTest::isUncaught('php-warning'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', BeaconSelfTest::newRunToken());
    }

    public function testStatusReportsTargetListenersAndErrors(): void
    {
        $status = $this->selfTest(new RecordingBeaconClient())->status();
        self::assertTrue($status['enabled']);
        self::assertSame('https://beacon.example.com:9444', $status['origin']);
        self::assertSame('42', $status['project_id']);
        self::assertNull($status['error']);
        self::assertSame(['exception' => true, 'console' => true, 'php_warning' => true, 'messenger' => true], $status['listeners']);
        self::assertFalse($status['messenger_bus']);

        $empty = (new BeaconSelfTest(new NullBeaconClient(), new BeaconDsnParser(), null))->status();
        self::assertFalse($empty['enabled']);
        self::assertNull($empty['origin']);
        self::assertNull($empty['error']);

        $invalid = (new BeaconSelfTest(new NullBeaconClient(), new BeaconDsnParser(), 'not-a-dsn'))->status();
        self::assertNotNull($invalid['error']);
    }

    public function testAssertReadyExplainsEveryMisconfiguration(): void
    {
        $cases = [
            'disabled' => [new BeaconSelfTest(new RecordingBeaconClient(), new BeaconDsnParser(), self::DSN, false), 'nowo_beacon.enabled: false'],
            'empty'    => [new BeaconSelfTest(new RecordingBeaconClient(), new BeaconDsnParser(), '  '), 'No Beacon DSN configured'],
            'invalid'  => [new BeaconSelfTest(new RecordingBeaconClient(), new BeaconDsnParser(), 'not-a-dsn'), 'Invalid Beacon DSN'],
            'client'   => [new BeaconSelfTest(new NullBeaconClient(), new BeaconDsnParser(), self::DSN), 'client is disabled'],
        ];

        foreach ($cases as $name => [$selfTest, $message]) {
            try {
                $selfTest->assertReady();
                self::fail('Expected exception for ' . $name);
            } catch (SelfTestUnavailableException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $name);
            }
        }

        $this->selfTest(new RecordingBeaconClient())->assertReady();
        $this->addToAssertionCount(1);
    }

    public function testRunRejectsUnknownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->selfTest(new RecordingBeaconClient())->run('nope');
    }

    public function testRunRequiresReadiness(): void
    {
        $this->expectException(SelfTestUnavailableException::class);
        (new BeaconSelfTest(new NullBeaconClient(), new BeaconDsnParser(), ''))->run('captured-message');
    }

    public function testUncaughtExceptionTagsAndThrows(): void
    {
        $client = new RecordingBeaconClient();

        try {
            $this->selfTest($client)->run('uncaught-exception', ' abc ');
            self::fail('Expected exception');
        } catch (BeaconSelfTestException $e) {
            self::assertSame('Beacon self-test: uncaught exception (run abc)', $e->getMessage());
        }

        self::assertSame(['beacon_selftest' => 'abc', 'beacon_selftest_kind' => 'uncaught-exception'], $client->tags);
        self::assertSame([], $client->exceptions);
    }

    public function testPhpErrorThrowsEngineError(): void
    {
        $this->expectException(DivisionByZeroError::class);
        $this->selfTest(new RecordingBeaconClient())->run('php-error');
    }

    public function testCapturedKindsReturnEventIds(): void
    {
        $client   = new RecordingBeaconClient();
        $selfTest = $this->selfTest($client);

        $exception = $selfTest->run('captured-exception', 'run1');
        self::assertSame(['kind' => 'captured-exception', 'run' => 'run1', 'event_id' => 'exception-id', 'delivery' => 'sent', 'tag' => 'beacon_selftest'], $exception->toArray());
        self::assertInstanceOf(BeaconSelfTestException::class, $client->exceptions[0]['throwable']);
        self::assertSame(['beacon_selftest' => ['kind' => 'captured-exception', 'run' => 'run1']], $client->exceptions[0]['extra']);

        $message = $selfTest->run('captured-message');
        self::assertSame('message-id', $message->eventId);
        self::assertSame('warning', $client->messages[0]['level']);
        self::assertStringContainsString('(run ' . $message->runToken . ')', $client->messages[0]['message']);
        self::assertSame($message->runToken, $client->tags['beacon_selftest']);
    }

    public function testPhpWarningGoesThroughErrorHandler(): void
    {
        $captured = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $level, string $message) use (&$captured): bool {
            $captured[] = [$level, $message];

            return true;
        });

        try {
            $result = $this->selfTest(new RecordingBeaconClient())->run('php-warning', 'w1');
        } finally {
            restore_error_handler();
        }

        self::assertSame([[E_USER_WARNING, 'Beacon self-test: PHP warning (run w1)']], $captured);
        self::assertNull($result->eventId);
        self::assertSame(SelfTestResult::DELIVERY_WARNING_HANDLER, $result->delivery);
    }

    public function testPhpWarningRequiresHandler(): void
    {
        $this->expectExceptionMessage('register_php_warning_handler');
        (new BeaconSelfTest(new RecordingBeaconClient(), new BeaconDsnParser(), self::DSN, phpWarningHandler: false))->run('php-warning');
    }

    public function testMessengerFailureRequiresBus(): void
    {
        $this->expectExceptionMessage('symfony/messenger');
        $this->selfTest(new RecordingBeaconClient())->run('messenger-failure');
    }

    public function testMessengerFailureSyncIsCapturedInline(): void
    {
        $client = new RecordingBeaconClient();
        $bus    = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            BeaconSelfTestFailMessage::class => [new BeaconSelfTestFailHandler()],
        ]))]);

        $result = $this->selfTest($client, $bus)->run('messenger-failure', 'm1');

        self::assertSame(SelfTestResult::DELIVERY_INLINE, $result->delivery);
        self::assertSame('exception-id', $result->eventId);
        $throwable = $client->exceptions[0]['throwable'];
        self::assertInstanceOf(HandlerFailedException::class, $throwable);
        $previous = $throwable->getPrevious();
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $previous);
        self::assertInstanceOf(BeaconSelfTestException::class, $previous->getPrevious());
        self::assertTrue($client->exceptions[0]['extra']['messenger']['sync']);
    }

    public function testMessengerFailureQueuedOrUnhandled(): void
    {
        $queued = $this->selfTest(new RecordingBeaconClient(), $this->bus(true))->run('messenger-failure', 'q1');
        self::assertSame(SelfTestResult::DELIVERY_QUEUED, $queued->delivery);
        self::assertNull($queued->eventId);

        $unsent = $this->selfTest(new RecordingBeaconClient(), $this->bus(false))->run('messenger-failure', 'q2');
        self::assertSame(SelfTestResult::DELIVERY_INLINE, $unsent->delivery);
        self::assertTrue($this->selfTest(new RecordingBeaconClient(), $this->bus(false))->status()['messenger_bus']);
    }

    public function testFlushDrainsPendingTransports(): void
    {
        $transport = $this->createMock(FlushableEnvelopeTransportInterface::class);
        $transport->expects(self::once())->method('flush');
        $registry = new PendingTransportRegistry();
        $registry->register($transport);

        (new BeaconSelfTest(new RecordingBeaconClient(), new BeaconDsnParser(), self::DSN, pendingTransports: $registry))->flush();
        $this->selfTest(new RecordingBeaconClient())->flush();

    }

    private function selfTest(RecordingBeaconClient $client, ?MessageBusInterface $bus = null): BeaconSelfTest
    {
        return new BeaconSelfTest($client, new BeaconDsnParser(), self::DSN, true, $bus);
    }

    private function bus(bool $sent): MessageBusInterface
    {
        return new class($sent) implements MessageBusInterface {
            public function __construct(private readonly bool $sent)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);

                return $this->sent ? $envelope->with(new SentStamp('async', 'async')) : $envelope;
            }
        };
    }
}
