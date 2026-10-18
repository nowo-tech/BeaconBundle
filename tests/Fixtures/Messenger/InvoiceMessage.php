<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Messenger;

/**
 * Plain Messenger message (tests only).
 */
final class InvoiceMessage
{
    public function __construct(
        public readonly string $number = 'INV-1',
    ) {
    }
}
