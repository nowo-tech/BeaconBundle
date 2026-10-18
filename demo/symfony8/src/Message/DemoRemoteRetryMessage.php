<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Sends an invoice through a flaky gateway (remote retry demo, routed to the Doctrine `async` transport).
 */
final class DemoRemoteRetryMessage
{
    public function __construct(
        public readonly string $invoiceNumber,
    ) {
    }
}
