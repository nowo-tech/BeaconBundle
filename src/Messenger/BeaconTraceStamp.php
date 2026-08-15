<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries a Beacon correlation id across Messenger hops.
 */
final class BeaconTraceStamp implements StampInterface
{
    public function __construct(
        public readonly string $traceId,
    ) {
    }
}
