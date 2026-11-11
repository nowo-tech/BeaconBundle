<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Ingest;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Ingest\IngestPathMode;
use Nowo\BeaconBundle\Ingest\IngestPathResolver;
use Nowo\BeaconBundle\Ingest\IngestPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

use function gc_collect_cycles;
use function memory_get_usage;

final class IngestPathResolverTest extends TestCase
{
    private const ORIGIN = 'https://beacon.example.com:9444';

    public function testUrlsForNumericAndUuidProjects(): void
    {
        $numeric = (new BeaconDsnParser())->parse('https://pk:sk@beacon.example.com:9444/5');
        $uuid    = (new BeaconDsnParser())->parse('http://pk:sk@beacon.internal/019FEA2D-507B-7890-8B33-CA488DB6F696');

        self::assertSame('https://beacon.example.com:9444/api/ingest/v1/5/envelope/', IngestPaths::envelopeUrl($numeric, true));
        self::assertSame('https://beacon.example.com:9444/api/5/envelope/', IngestPaths::envelopeUrl($numeric, false));
        self::assertSame($numeric->getEnvelopeUrl(), IngestPaths::envelopeUrl($numeric, false), 'legacy URL unchanged');
        self::assertSame(
            'http://beacon.internal/api/ingest/v1/019fea2d-507b-7890-8b33-ca488db6f696/messenger/commands/claim',
            IngestPaths::projectPathUrl($uuid, '/messenger/commands/claim', true),
        );
        self::assertSame(
            'http://beacon.internal/api/019fea2d-507b-7890-8b33-ca488db6f696/messenger/commands/abc/result',
            IngestPaths::projectPathUrl($uuid, '/messenger/commands/abc/result', false),
        );
        self::assertStringNotContainsString('sk', IngestPaths::envelopeUrl($numeric, true));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '404' => [404, true];
        yield '405' => [405, true];
        foreach ([200, 202, 400, 401, 403, 413, 429, 500, 503] as $status) {
            yield (string) $status => [$status, false];
        }
    }

    #[DataProvider('statuses')]
    public function testOnlyRouteMissingStatusesFallBack(int $status, bool $fallback): void
    {
        $resolver = new IngestPathResolver();

        self::assertTrue($resolver->isProbing(self::ORIGIN));
        self::assertTrue($resolver->useVersioned(self::ORIGIN));
        self::assertSame($fallback, IngestPaths::isRouteMissing($status));
        self::assertSame($fallback, $resolver->versionedAnswered(self::ORIGIN, $status));
        if (!$fallback) {
            self::assertFalse($resolver->isProbing(self::ORIGIN), 'any other answer proves the versioned path exists');
            self::assertTrue($resolver->useVersioned(self::ORIGIN));
        }
    }

    public function testLegacyAnswerIsRememberedPerOrigin(): void
    {
        $resolver = new IngestPathResolver(IngestPathMode::Auto);

        self::assertTrue($resolver->versionedAnswered(self::ORIGIN, 404));
        $resolver->legacyAnswered(self::ORIGIN, 202);

        self::assertFalse($resolver->isProbing(self::ORIGIN));
        self::assertFalse($resolver->useVersioned(self::ORIGIN));
        self::assertTrue($resolver->useVersioned('https://other.example.com'), 'other origins are still probed');
        // A request already started on the versioned path while probing still falls back.
        self::assertTrue($resolver->versionedAnswered(self::ORIGIN, 404));
        self::assertSame(1, $resolver->count());
    }

    public function testBothPathsMissingKeepsTheVersionedPathAndStopsProbing(): void
    {
        $resolver = new IngestPathResolver('auto');

        self::assertTrue($resolver->versionedAnswered(self::ORIGIN, 404));
        $resolver->legacyAnswered(self::ORIGIN, 404);

        self::assertFalse($resolver->isProbing(self::ORIGIN));
        self::assertTrue($resolver->useVersioned(self::ORIGIN));
        self::assertFalse($resolver->versionedAnswered(self::ORIGIN, 404), 'no second probe');
    }

    public function testForcedModesNeverProbeNorLearn(): void
    {
        $versioned = new IngestPathResolver(IngestPathMode::Versioned);
        $legacy    = new IngestPathResolver('legacy');

        self::assertSame(IngestPathMode::Versioned, $versioned->mode());
        self::assertSame(IngestPathMode::Legacy, $legacy->mode());
        self::assertTrue($versioned->useVersioned(self::ORIGIN));
        self::assertFalse($legacy->useVersioned(self::ORIGIN));
        self::assertFalse($versioned->isProbing(self::ORIGIN));
        self::assertFalse($versioned->versionedAnswered(self::ORIGIN, 404));
        $versioned->legacyAnswered(self::ORIGIN, 200);
        self::assertSame(0, $versioned->count());
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(ValueError::class);
        new IngestPathResolver('v2'); // @phpstan-ignore argument.type
    }

    public function testResetForgetsEveryOrigin(): void
    {
        $resolver = new IngestPathResolver();
        $resolver->versionedAnswered(self::ORIGIN, 404);
        $resolver->legacyAnswered(self::ORIGIN, 200);
        self::assertFalse($resolver->useVersioned(self::ORIGIN));

        $resolver->reset();

        self::assertSame(0, $resolver->count());
        self::assertTrue($resolver->isProbing(self::ORIGIN));
        self::assertTrue($resolver->useVersioned(self::ORIGIN));
    }

    public function testTheMapIsBoundedAndMemoryStaysFlatOverManyOrigins(): void
    {
        // FrankenPHP worker mode without kernel reset: the same instance serves thousands of requests.
        $resolver = new IngestPathResolver();
        for ($i = 0; $i < 500; ++$i) {
            $resolver->versionedAnswered('https://warm-' . $i . '.example.com', 404);
            $resolver->legacyAnswered('https://warm-' . $i . '.example.com', 200);
        }
        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 0; $i < 5000; ++$i) {
            $origin = 'https://host-' . $i . '.example.com';
            if ($resolver->versionedAnswered($origin, 404)) {
                $resolver->legacyAnswered($origin, 202);
            }
            self::assertLessThanOrEqual(IngestPathResolver::MAX_ORIGINS, $resolver->count());
        }
        gc_collect_cycles();

        self::assertSame(IngestPathResolver::MAX_ORIGINS, $resolver->count());
        self::assertLessThan(64 * 1024, memory_get_usage() - $before);
        self::assertTrue($resolver->isProbing('https://host-0.example.com'), 'oldest origins were dropped');
        self::assertFalse($resolver->useVersioned('https://host-4999.example.com'));
    }
}
