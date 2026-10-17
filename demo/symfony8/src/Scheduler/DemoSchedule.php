<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\DemoFlakyTaskMessage;
use App\Message\DemoHeartbeatMessage;
use App\Message\DemoSlowSyncMessage;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Demo schedule for Beacon Scheduler check-ins (`nowo_beacon.scheduler.check_ins`).
 *
 * Run the worker: `make scheduler` (or `php bin/console messenger:consume scheduler_default -vv`).
 * On start it sends one `schedule_catalog`; every run sends `in_progress` then `ok` / `error` check-ins.
 */
#[AsSchedule('default')]
final class DemoSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())->add(
            // Fast task: always `ok`.
            RecurringMessage::every('30 seconds', new DemoHeartbeatMessage()),
            // Slow task: #[BeaconCheckIn(maxRuntime: 5)] but sleeps 8 s → Beacon reports "run too long".
            RecurringMessage::every('1 minute', new DemoSlowSyncMessage()),
            // Failing task: `error` check-in linked to the exception event (failure_event_id).
            RecurringMessage::every('2 minutes', new DemoFlakyTaskMessage()),
            // Console command: only the command name is sent (never arguments); max runtime from config.
            RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:demo-nightly-report --since=yesterday'), 'Europe/Madrid'),
        );
    }
}
