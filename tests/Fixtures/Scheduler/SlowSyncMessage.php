<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

use Nowo\BeaconBundle\Attribute\BeaconCheckIn;

#[BeaconCheckIn(maxRuntime: 600, description: 'Syncs the product catalog from the ERP.')]
final class SlowSyncMessage
{
}
