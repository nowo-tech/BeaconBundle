<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Support;

use Nowo\BeaconBundle\Support\IgnoredRequestPath;
use PHPUnit\Framework\TestCase;

final class IgnoredRequestPathTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function defaultPathProvider(): iterable
    {
        yield 'profiler' => ['/_profiler/abc'];
        yield 'wdt' => ['/_wdt/token'];
        yield 'build' => ['/build/app.js'];
        yield 'assets' => ['/assets/logo.svg'];
        yield 'health live' => ['/health/live'];
        yield 'health ready' => ['/health/ready'];
        yield 'chrome trailing slash' => ['/.well-known/appspecific/com.chrome.devtools.json/'];
        yield 'chrome no trailing slash' => ['/.well-known/appspecific/com.chrome.devtools.json'];
    }

    /**
     * @dataProvider defaultPathProvider
     */
    public function testDefaultsMatchInfraAndChromeNoise(string $path): void
    {
        self::assertTrue(IgnoredRequestPath::matches($path, IgnoredRequestPath::DEFAULTS));
    }

    public function testDoesNotMatchUnrelatedPaths(): void
    {
        self::assertFalse(IgnoredRequestPath::matches('/account/profile', IgnoredRequestPath::DEFAULTS));
        self::assertFalse(IgnoredRequestPath::matches('/api/1/envelope/', IgnoredRequestPath::DEFAULTS));
        self::assertFalse(IgnoredRequestPath::matches(
            '/.well-known/appspecific/com.chrome.devtools.json.evil',
            IgnoredRequestPath::DEFAULTS,
        ));
        self::assertFalse(IgnoredRequestPath::matches('/building', IgnoredRequestPath::DEFAULTS));
    }

    public function testEmptyIgnoreListNeverMatches(): void
    {
        self::assertFalse(IgnoredRequestPath::matches('/.well-known/appspecific/com.chrome.devtools.json', []));
    }

    public function testEmptyIgnoreEntryIsSkipped(): void
    {
        self::assertFalse(IgnoredRequestPath::matches('/account', ['', '']));
        self::assertTrue(IgnoredRequestPath::matches('/_profiler/x', ['', '/_profiler']));
    }

    public function testRootPathNormalizesTrailingSlashes(): void
    {
        self::assertTrue(IgnoredRequestPath::matches('/', ['/']));
        self::assertTrue(IgnoredRequestPath::matches('///', ['/']));
    }

    public function testPrefixMatchAllowsSubPaths(): void
    {
        self::assertTrue(IgnoredRequestPath::matches('/noise/probe/extra', ['/noise/probe']));
    }
}
