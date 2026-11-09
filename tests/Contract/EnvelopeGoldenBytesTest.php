<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Contract;

use Nowo\BeaconBundle\Tests\Fixtures\Golden\GoldenCases;
use Nowo\BeaconBundle\Tests\Fixtures\Golden\Legacy\LegacyEnvelopeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function in_array;
use function sprintf;

/**
 * Byte-for-byte envelope goldens.
 *
 * - `bundle-1.19/`: beacon-php's recordings of BeaconBundle 1.19.1 (`nowo-tech/beacon-php` `tests/Fixtures/Golden`).
 * - `bundle-1.20/`: the same cases plus the `*_1192` redaction cases, recorded from BeaconBundle 1.20.0
 *   (`BEACON_GOLDEN_UPDATE=1` re-records them from {@see LegacyEnvelopeBuilder}).
 *
 * The current builder must reproduce every file except the envelope header `dsn`, which 1.21 no longer sends.
 */
final class EnvelopeGoldenBytesTest extends TestCase
{
    private const DIR = __DIR__ . '/../Fixtures/Golden';

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        foreach (GoldenCases::names() as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bundle119Cases(): iterable
    {
        foreach (GoldenCases::BUNDLE_119 as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('cases')]
    public function testFrozenBuilderMatchesThe120Recording(string $name): void
    {
        $file = sprintf('%s/bundle-1.20/%s.ndjson', self::DIR, $name);
        if (getenv('BEACON_GOLDEN_UPDATE') === '1') {
            file_put_contents($file, GoldenCases::legacy($name));
        }

        self::assertSame($this->golden($file), GoldenCases::legacy($name));
    }

    #[DataProvider('bundle119Cases')]
    public function test119RecordingEqualsThe120Recording(string $name): void
    {
        self::assertTrue(in_array($name, GoldenCases::names(), true));
        self::assertSame(
            $this->golden(sprintf('%s/bundle-1.19/%s.ndjson', self::DIR, $name)),
            $this->golden(sprintf('%s/bundle-1.20/%s.ndjson', self::DIR, $name)),
            'The envelope of this input changed between 1.19.1 and 1.20.0.',
        );
    }

    #[DataProvider('cases')]
    public function testCurrentBuilderMatchesThe120RecordingWithoutHeaderDsn(string $name): void
    {
        $expected = GoldenCases::withoutHeaderDsn($this->golden(sprintf('%s/bundle-1.20/%s.ndjson', self::DIR, $name)));

        self::assertStringNotContainsString('"dsn"', $expected);
        self::assertSame($expected, GoldenCases::current($name));
    }

    private function golden(string $file): string
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, 'Missing golden file ' . $file);

        return $contents;
    }
}
