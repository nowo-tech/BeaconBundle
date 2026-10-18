<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Command;

use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\SchedulerCatalogCommand;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\CheckInClient;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\FixedScheduleProvider;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\PlainMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\SlowSyncMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CallbackMessageProvider;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class SchedulerCatalogCommandTest extends TestCase
{
    public function testSendsCatalogForAllOrSelectedSchedules(): void
    {
        $client = new RecordingBeaconClient();
        $tester = new CommandTester(new SchedulerCatalogCommand($client, $this->builder(), true));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Schedule catalog sent (event catalog-id).', $display);
        self::assertStringContainsString('app:reports:nightly', $display);
        self::assertStringContainsString('600 s', $display);
        self::assertStringContainsString('Syncs the product catalog from the ERP.', $display);
        self::assertStringContainsString('(callback provider)', $display);
        self::assertStringNotContainsString('--token', $display);
        self::assertSame(['default', 'reports'], array_column($client->catalogs[0], 'name'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--schedule' => ['reports']]));
        self::assertSame(['reports'], array_column($client->catalogs[1], 'name'));
    }

    public function testDryRunUnknownScheduleDisabledAndEmpty(): void
    {
        $client = new RecordingBeaconClient();

        $dry = new CommandTester(new SchedulerCatalogCommand($client, $this->builder(), true));
        self::assertSame(Command::SUCCESS, $dry->execute(['--dry-run' => true]));
        self::assertStringContainsString('Dry run: catalog not sent.', $dry->getDisplay());

        self::assertSame(Command::FAILURE, $dry->execute(['--schedule' => ['nope']]));
        self::assertStringContainsString('Unknown schedule(s): nope', $dry->getDisplay());

        $off = new CommandTester(new SchedulerCatalogCommand($client, $this->builder(), false));
        self::assertSame(Command::SUCCESS, $off->execute([]));
        self::assertStringContainsString('check_ins.enabled is false', $off->getDisplay());

        $null = new CommandTester(new SchedulerCatalogCommand(new NullBeaconClient(), $this->builder(), true));
        self::assertSame(Command::SUCCESS, $null->execute([]));
        self::assertStringContainsString('Beacon reporting is disabled', $null->getDisplay());

        $none = new CommandTester(new SchedulerCatalogCommand($client, new ScheduleCatalogBuilder(new CheckInPolicy()), true));
        self::assertSame(Command::SUCCESS, $none->execute([]));
        self::assertStringContainsString('No Symfony Scheduler schedules found.', $none->getDisplay());

        self::assertSame([], $client->catalogs);
    }

    public function testEventIdMayBeNull(): void
    {
        $client = $this->createMock(CheckInClient::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureScheduleCatalog')->willReturn(null);
        $tester = new CommandTester(new SchedulerCatalogCommand($client, $this->builder(), true));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Schedule catalog sent.', $tester->getDisplay());
    }

    private function builder(): ScheduleCatalogBuilder
    {
        $default = (new Schedule())->add(
            RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:reports:nightly --token=s3cr3t'), 'UTC'),
            RecurringMessage::every(900, new SlowSyncMessage()),
            RecurringMessage::trigger(new PeriodicalTrigger(60), new CallbackMessageProvider(static fn (): array => [new PlainMessage()], 'cb')),
        );
        $reports = (new Schedule())->add(RecurringMessage::every(3600, new PlainMessage()));

        return new ScheduleCatalogBuilder(
            new CheckInPolicy(),
            new ServiceLocator([
                'default' => static fn (): FixedScheduleProvider => new FixedScheduleProvider($default),
                'reports' => static fn (): FixedScheduleProvider => new FixedScheduleProvider($reports),
            ]),
            new MockClock('2026-10-17T10:00:00+00:00'),
        );
    }
}
