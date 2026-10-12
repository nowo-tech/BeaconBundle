<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\SelfTest\Message;

use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;

/**
 * Self-test Messenger message whose handler always fails.
 *
 * Route it to an async transport so a worker raises WorkerMessageFailedEvent and
 * {@see BeaconMessengerFailedListener} reports it (no retry).
 * Unrouted (synchronous) dispatches are captured inline by {@see BeaconSelfTest}.
 */
final readonly class BeaconSelfTestFailMessage
{
    public function __construct(
        public string $runToken,
    ) {
    }
}
