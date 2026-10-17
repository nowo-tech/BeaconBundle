<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

use RuntimeException;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

final class BrokenScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        throw new RuntimeException('schedule unavailable');
    }
}
