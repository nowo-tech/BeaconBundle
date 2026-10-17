<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\BeaconBundle\Scheduler\ScheduledRun;
use Nowo\BeaconBundle\Scheduler\SchedulerRunRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class SchedulerRunRegistryTest extends TestCase
{
    public function testRunIdIsDeterministicAcrossTimeZones(): void
    {
        $trigger = new PeriodicalTrigger(60);
        $utc     = new MessageContext('default', 'abc', $trigger, new DateTimeImmutable('2026-10-17T01:00:00+00:00'));
        $madrid  = new MessageContext('default', 'abc', $trigger, (new DateTimeImmutable('2026-10-17T01:00:00+00:00'))->setTimezone(new DateTimeZone('Europe/Madrid')));

        $expected = substr(hash('sha256', "default\nabc\n2026-10-17T01:00:00+00:00"), 0, 32);
        self::assertSame($expected, ScheduledRun::runId($utc));
        self::assertSame($expected, ScheduledRun::runId($madrid));
        self::assertSame('2026-10-17T01:00:00+00:00', ScheduledRun::atom($madrid->triggeredAt));
        self::assertNotSame($expected, ScheduledRun::runId(new MessageContext('other', 'abc', $trigger, $utc->triggeredAt)));
    }

    public function testStartFinishFailureAndBounds(): void
    {
        $registry = new SchedulerRunRegistry();
        $registry->start('run-a', 10.5);
        self::assertSame(1, $registry->runningCount());
        self::assertSame(10.5, $registry->finish('run-a'));
        self::assertNull($registry->finish('run-a'));

        $registry->rememberFailureEvent('run-a', 'event-1');
        self::assertSame('event-1', $registry->takeFailureEvent('run-a'));
        self::assertNull($registry->takeFailureEvent('run-a'));

        for ($i = 0; $i < SchedulerRunRegistry::MAX_RUNS + 5; ++$i) {
            $registry->start('run-' . $i, (float) $i);
            $registry->rememberFailureEvent('run-' . $i, 'event-' . $i);
        }
        // Re-starting a known run does not evict anything.
        $registry->start('run-50', 50.0);
        $registry->rememberFailureEvent('run-50', 'event-50');
        self::assertSame(SchedulerRunRegistry::MAX_RUNS, $registry->runningCount());
        self::assertNull($registry->finish('run-0'), 'oldest run evicted');
        self::assertNull($registry->takeFailureEvent('run-0'), 'oldest failure evicted');
        self::assertSame(104.0, $registry->finish('run-104'));

        $registry->reset();
        self::assertSame(0, $registry->runningCount());
        self::assertNull($registry->takeFailureEvent('run-104'));
    }
}
