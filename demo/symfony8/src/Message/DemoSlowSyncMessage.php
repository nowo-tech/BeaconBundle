<?php

declare(strict_types=1);

namespace App\Message;

use Nowo\BeaconBundle\Attribute\BeaconCheckIn;

/**
 * Slow scheduled task: the handler sleeps longer than the declared max runtime.
 */
#[BeaconCheckIn(maxRuntime: 5)]
final class DemoSlowSyncMessage
{
    public function __construct(
        public readonly int $sleepSeconds = 8,
    ) {
    }
}
