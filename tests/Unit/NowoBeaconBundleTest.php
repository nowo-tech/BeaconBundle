<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit;

use Nowo\BeaconBundle\NowoBeaconBundle;
use PHPUnit\Framework\TestCase;

final class NowoBeaconBundleTest extends TestCase
{
    public function testBundleCanBeConstructed(): void
    {
        $bundle = new NowoBeaconBundle();

        self::assertSame('NowoBeaconBundle', $bundle->getName());
    }
}
