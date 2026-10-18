<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

/**
 * Sends the weekly   digest e-mail to <b>subscribers</b>. Batches of 500, see {@see ReportMessageInterface}.
 *
 * @internal tests only
 */
final class PlainMessage implements ReportMessageInterface
{
    public function __construct(
        public readonly string $secret = 'never-sent',
    ) {
    }
}
