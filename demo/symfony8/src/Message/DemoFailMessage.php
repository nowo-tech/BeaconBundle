<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Demo Messenger payload used to simulate a final worker failure for Beacon.
 */
final class DemoFailMessage
{
    public function __construct(
        public readonly string $reason = 'demo-fail',
        public readonly int $attempt = 1,
    ) {
    }
}
