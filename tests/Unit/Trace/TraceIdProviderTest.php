<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Trace;

use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;

final class TraceIdProviderTest extends TestCase
{
    public function testGeneratesAndResets(): void
    {
        $provider = new TraceIdProvider();
        $first    = $provider->getOrCreate();
        self::assertSame($first, $provider->getOrCreate());
        $provider->reset();
        self::assertNull($provider->get());
        self::assertNotSame($first, $provider->getOrCreate());
    }

    public function testNormalizesHeaderValues(): void
    {
        self::assertNull(TraceIdProvider::normalize('bad id'));
        self::assertSame('abc_def-12', TraceIdProvider::normalize(' abc_def-12 '));
    }
}
