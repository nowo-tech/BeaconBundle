<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Client;

use Nowo\BeaconBundle\Client\ClientUserAgent;
use PHPUnit\Framework\TestCase;

final class ClientUserAgentTest extends TestCase
{
    public function testResolveReturnsBeaconBundlePrefix(): void
    {
        $ua = ClientUserAgent::resolve();

        self::assertStringStartsWith('beacon-bundle/', $ua);
        self::assertNotSame('beacon-bundle/', $ua);
    }

    public function testResolveFallsBackWhenPackageIsNotInstalled(): void
    {
        self::assertSame('beacon-bundle/1.6', ClientUserAgent::resolve('nowo-tech/definitely-not-installed-package'));
    }
}
