<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\BeaconBundle\Command\MessengerCommandsCommand;
use Nowo\BeaconBundle\Messenger\RemoteRetry\FailedMessageCommandExecutor;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandPoller;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\InvoiceMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\ScriptedListableTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

use function json_encode;

final class MessengerCommandsCommandTest extends TestCase
{
    private float $now = 0.0;

    /** @var list<float> */
    private array $sleeps = [];

    public function testDisabledFeatureFails(): void
    {
        $tester = new CommandTester(new MessengerCommandsCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        self::assertStringContainsString('remote_retry.enabled', $tester->getDisplay());
    }

    public function testInvalidTimeLimit(): void
    {
        $tester = new CommandTester($this->command([]));

        self::assertSame(Command::INVALID, $tester->execute(['--time-limit' => 'abc']));
        self::assertSame(Command::INVALID, $tester->execute(['--time-limit' => '-1']));
    }

    public function testOncePrintsTheExecutedCommands(): void
    {
        $tester = new CommandTester($this->command([
            new MockResponse((string) json_encode(['commands' => [RemoteRetryFixtures::commandData()], 'poll_after' => 30])),
            new MockResponse('{}'),
        ]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Poll: ok, next in 30s', $display);
        self::assertStringContainsString('0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f6', $display);
        self::assertStringContainsString('done (requeued)', $display);
        self::assertStringNotContainsString(RemoteRetryFixtures::WORKER_KEY, $display);
        self::assertStringNotContainsString('claim-token-1', $display);
    }

    public function testOnceFailsOnAuthErrorsAndMissingKey(): void
    {
        $tester = new CommandTester($this->command([new MockResponse('', ['http_code' => 401])]));
        self::assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        self::assertStringContainsString('auth_error', $tester->getDisplay());

        $tester = new CommandTester($this->command([], RemoteRetryFixtures::credentials(key: '')));
        self::assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        self::assertStringContainsString('worker_key is empty', $tester->getDisplay());

        $tester = new CommandTester($this->command([new MockResponse('', ['http_code' => 500])]));
        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]), 'transient errors do not fail cron runs');
    }

    public function testLoopRespectsScheduleAndTimeLimit(): void
    {
        $empty  = static fn (): MockResponse => new MockResponse((string) json_encode(['commands' => [], 'poll_after' => 30]));
        $tester = new CommandTester($this->command([$empty(), $empty(), $empty()]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--time-limit' => '70']));
        self::assertSame(3, substr_count($tester->getDisplay(), 'Poll: ok'));
        self::assertSame([30.0, 30.0, 10.0], $this->sleeps);
    }

    public function testLoopStopsWhenDisabled(): void
    {
        $tester = new CommandTester($this->command([], RemoteRetryFixtures::credentials(key: '')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testDefaultSleepAndClock(): void
    {
        $poller  = $this->poller([new MockResponse((string) json_encode(['commands' => []]))], RemoteRetryFixtures::credentials());
        $command = new MessengerCommandsCommand($poller);
        $tester  = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--time-limit' => '0.05']));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function command(array $responses, ?RemoteRetryCredentials $credentials = null): MessengerCommandsCommand
    {
        return new MessengerCommandsCommand(
            $this->poller($responses, $credentials ?? RemoteRetryFixtures::credentials()),
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
            fn (): float => $this->now,
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function poller(array $responses, RemoteRetryCredentials $credentials): RemoteCommandPoller
    {
        $failed                   = new ScriptedListableTransport();
        $async                    = new ScriptedListableTransport();
        $failed->messages['8812'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);

        $client   = new RemoteCommandClient(new MockHttpClient($responses), $credentials, 'worker-2', 'prod');
        $executor = new FailedMessageCommandExecutor(
            $credentials,
            new ServiceLocator(['failed' => static fn (): ScriptedListableTransport => $failed, 'async' => static fn (): ScriptedListableTransport => $async]),
            ['retry', 'discard'],
            ['failed'],
            null,
            new MockClock(new DateTimeImmutable('2026-10-17T10:03:00Z')),
        );

        return new RemoteCommandPoller($client, $executor, $credentials, 30, null, fn (): float => $this->now);
    }
}
