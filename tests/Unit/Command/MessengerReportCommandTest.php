<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Command;

use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\MessengerReportCommand;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class MessengerReportCommandTest extends TestCase
{
    public function testSendsCountsPerTransportAndFlagsFailureTransports(): void
    {
        $client = new RecordingBeaconClient();
        $tester = new CommandTester(new MessengerReportCommand($client, $this->locator(), ['failed']));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Queue snapshot sent (event snapshot-id).', $tester->getDisplay());

        self::assertSame([[
            ['name' => 'async', 'count' => 7, 'failure' => false],
            ['name' => 'broken', 'count' => null, 'failure' => false],
            ['name' => 'failed', 'count' => 2, 'failure' => true],
            ['name' => 'sync', 'count' => null, 'failure' => false],
            ['name' => 'unknown', 'count' => null, 'failure' => false],
        ]], $client->snapshots);
    }

    public function testFailureTransportOptionOverridesConfigAndDryRunSendsNothing(): void
    {
        $client = new RecordingBeaconClient();
        $tester = new CommandTester(new MessengerReportCommand($client, $this->locator(), ['failed']));

        self::assertSame(Command::SUCCESS, $tester->execute(['--failure-transport' => ['async'], '--dry-run' => true]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertMatchesRegularExpression('/async\s+7\s+yes/', $tester->getDisplay());
        self::assertSame([], $client->snapshots);
    }

    public function testDisabledClientAndMissingMessenger(): void
    {
        $disabled = new CommandTester(new MessengerReportCommand(new NullBeaconClient(), $this->locator()));
        self::assertSame(Command::SUCCESS, $disabled->execute([]));
        self::assertStringContainsString('snapshot not sent', $disabled->getDisplay());

        $noMessenger = new CommandTester(new MessengerReportCommand(new RecordingBeaconClient()));
        self::assertSame(Command::FAILURE, $noMessenger->execute([]));

        $empty = new CommandTester(new MessengerReportCommand(new RecordingBeaconClient(), new ServiceLocator([])));
        self::assertSame(Command::SUCCESS, $empty->execute([]));
        self::assertStringContainsString('No Messenger transports found', $empty->getDisplay());
    }

    /**
     * @return ServiceLocator<mixed>
     */
    private function locator(): ServiceLocator
    {
        $async  = $this->countingReceiver(7);
        $failed = $this->countingReceiver(2);
        $broken = $this->createStub(CountingReceiver::class);
        $broken->method('getMessageCount')->willThrowException(new RuntimeException('redis down'));

        return new ServiceLocator([
            'async'                     => static fn (): CountingReceiver => $async,
            'messenger.transport.async' => static fn (): CountingReceiver => $async,
            'failed'                    => static fn (): CountingReceiver => $failed,
            'broken'                    => static fn (): Stub => $broken,
            'sync'                      => fn (): Stub => $this->createStub(ReceiverInterface::class),
            'unknown'                   => static fn () => throw new RuntimeException('cannot build'),
        ]);
    }

    private function countingReceiver(int $count): CountingReceiver
    {
        $receiver = $this->createStub(CountingReceiver::class);
        $receiver->method('getMessageCount')->willReturn($count);

        return $receiver;
    }
}

/**
 * @internal
 */
interface CountingReceiver extends ReceiverInterface, MessageCountAwareInterface
{
}
