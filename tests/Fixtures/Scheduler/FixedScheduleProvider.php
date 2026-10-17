<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

final class FixedScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        private readonly Schedule $schedule,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }
}
