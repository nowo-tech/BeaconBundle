<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Tests\Fixtures\Equivalence\ContainerSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function file_get_contents;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * REQ-ARCH-001 / 1.25 equivalence: without `credentials_provider` the extension registers exactly what 1.24.1
 * registered (definitions, factories, arguments, tags, aliases, parameters), apart from the inert credentials resolver,
 * the rotate command and null `$resolver` / `$credentials` arguments. The golden file was generated from the 1.24.1
 * sources (commit 4a6ffe7) with {@see ContainerSnapshot}.
 */
final class DefaultWiringEquivalenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function configurations(): iterable
    {
        foreach (array_keys(ContainerSnapshot::CONFIGS) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('configurations')]
    public function testWiringWithoutProviderIsIdenticalTo1241(string $name): void
    {
        $golden = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/Equivalence/container-1.24.1.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($golden);

        // Round-trip through JSON so both sides have the same scalar / array shapes.
        $current = json_decode(json_encode(ContainerSnapshot::take($name), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($golden[$name], $current);
    }
}
