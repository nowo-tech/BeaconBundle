<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

use Nowo\BeaconBundle\Attribute\BeaconCheckIn;

#[BeaconCheckIn(maxRuntime: 999999999)]
final class OutOfRangeMessage
{
}
