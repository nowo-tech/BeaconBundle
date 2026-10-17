<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

use Nowo\BeaconBundle\Attribute\BeaconCheckIn;

/**
 * Invalid attribute argument: instantiating the attribute throws a TypeError (the policy ignores it).
 *
 * @phpstan-ignore argument.type
 */
#[BeaconCheckIn(maxRuntime: 'ten minutes')]
final class BrokenAttributeMessage
{
}
