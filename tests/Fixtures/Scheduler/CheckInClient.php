<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\CheckInCapableInterface;

/**
 * Mockable client type with the check-in capability (tests only).
 */
interface CheckInClient extends BeaconClientInterface, CheckInCapableInterface
{
}
