<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

final class PlainMessage implements ReportMessageInterface
{
    public function __construct(
        public readonly string $secret = 'never-sent',
    ) {
    }
}
