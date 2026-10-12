<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Command;

use DivisionByZeroError;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\SelfTestCommand;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTestException;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailHandler;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\SentStamp;

use function method_exists;
use function restore_error_handler;
use function set_error_handler;

final class SelfTestCommandTest extends TestCase
{
    private const DSN = 'https://pub:secret@beacon.example.com/7';

    public function testWithoutTypeListsKinds(): void
    {
        $tester = new CommandTester(new SelfTestCommand($this->selfTest(new RecordingBeaconClient())));

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('messenger-failure', $tester->getDisplay());
        self::assertStringContainsString('all', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['type' => 'bogus']));
        self::assertStringContainsString('Unknown self-test type "bogus"', $tester->getDisplay());
    }

    public function testFailsClearlyWithoutDsn(): void
    {
        $tester = new CommandTester(new SelfTestCommand(new BeaconSelfTest(new NullBeaconClient(), new BeaconDsnParser(), '')));

        self::assertSame(Command::FAILURE, $tester->execute(['type' => 'captured-message']));
        self::assertStringContainsString('No Beacon DSN configured', $tester->getDisplay());
    }

    public function testCapturedExceptionViaOption(): void
    {
        $client = new RecordingBeaconClient();
        $tester = new CommandTester(new SelfTestCommand($this->selfTest($client)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'captured-exception', '--run' => 'cli1']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('beacon.example.com', $display);
        self::assertStringContainsString('exception-id', $display);
        self::assertStringContainsString('beacon_selftest:cli1', $display);
        self::assertSame('cli1', $client->tags['beacon_selftest']);
        self::assertCount(1, $client->exceptions);
    }

    public function testAllSkipsMessengerWithoutBus(): void
    {
        $client = new RecordingBeaconClient();
        $tester = new CommandTester(new SelfTestCommand($this->selfTest($client)));

        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static fn (): bool => true);
        try {
            $exit = $tester->execute(['type' => 'all']);
        } finally {
            restore_error_handler();
        }

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('skipped (no Messenger bus)', $tester->getDisplay());
        self::assertCount(1, $client->exceptions);
        self::assertCount(1, $client->messages);
    }

    public function testAllWithBusAndUnavailableKind(): void
    {
        $client   = new RecordingBeaconClient();
        $selfTest = new BeaconSelfTest($client, new BeaconDsnParser(), self::DSN, true, $this->queueingBus(), phpWarningHandler: false);
        $tester   = new CommandTester(new SelfTestCommand($selfTest));

        self::assertSame(Command::FAILURE, $tester->execute(['type' => 'all']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('unavailable:', $display);
        self::assertStringContainsString('queued', $display);
        self::assertStringContainsString('messenger:consume', $display);
        self::assertStringContainsString('Some self-test kinds could not run', $display);
    }

    public function testMessengerFailureSync(): void
    {
        $client = new RecordingBeaconClient();
        $bus    = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            BeaconSelfTestFailMessage::class => [new BeaconSelfTestFailHandler()],
        ]))]);
        $tester = new CommandTester(new SelfTestCommand(new BeaconSelfTest($client, new BeaconDsnParser(), self::DSN, true, $bus)));

        self::assertSame(Command::SUCCESS, $tester->execute(['type' => 'messenger-failure']));
        self::assertStringContainsString('inline', $tester->getDisplay());
        self::assertCount(1, $client->exceptions);
    }

    public function testUncaughtExceptionReachesConsoleErrorListener(): void
    {
        $client     = new RecordingBeaconClient();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new BeaconConsoleErrorListener($client));

        $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        $command = new SelfTestCommand($this->selfTest($client));
        // @phpstan-ignore function.alreadyNarrowedType (Symfony Console 7.x has no addCommand())
        if (method_exists($application, 'addCommand')) {
            $application->addCommand($command);
        } else {
            // @phpstan-ignore method.notFound (Symfony Console 7.x)
            $application->add($command);
        }

        $tester = new ApplicationTester($application);
        $exit   = $tester->run(['command' => 'nowo:beacon:self-test', 'type' => 'uncaught-exception', '--run' => 'u1']);

        self::assertSame(1, $exit);
        self::assertCount(1, $client->exceptions);
        self::assertInstanceOf(BeaconSelfTestException::class, $client->exceptions[0]['throwable']);
        self::assertSame('nowo:beacon:self-test', $client->exceptions[0]['extra']['console']['command']);
        self::assertSame('u1', $client->tags['beacon_selftest']);
        self::assertStringContainsString('The command exits with an error', $tester->getDisplay());
    }

    public function testUncaughtWarnsWhenConsoleListenerDisabled(): void
    {
        $selfTest = new BeaconSelfTest(new RecordingBeaconClient(), new BeaconDsnParser(), self::DSN, consoleListener: false);
        $tester   = new CommandTester(new SelfTestCommand($selfTest));

        try {
            $tester->execute(['type' => 'php-error']);
            self::fail('Expected error');
        } catch (DivisionByZeroError) {
            // expected
        }

        self::assertStringContainsString('register_console_listener is false', $tester->getDisplay());
    }

    private function selfTest(RecordingBeaconClient $client): BeaconSelfTest
    {
        return new BeaconSelfTest($client, new BeaconDsnParser(), self::DSN);
    }

    private function queueingBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return Envelope::wrap($message, $stamps)->with(new SentStamp('async', 'async'));
            }
        };
    }
}
