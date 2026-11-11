<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Ingest;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Ingest\IngestPathMode;
use Nowo\BeaconBundle\Ingest\IngestPathResolver;
use Nowo\BeaconBundle\Ingest\IngestPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

use function constant;
use function gc_collect_cycles;
use function memory_get_usage;

final class IngestPathResolverTest extends TestCase
{
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
     * @return iterable<string, array{int, bool, bool}> status, falls back, proves the route
     */
    public static function statuses(): iterable
    {
        yield '404' => [404, true, false];
        yield '405' => [405, true, false];
        foreach ([200, 202, 204, 400, 401, 403, 413, 415, 422] as $status) {
            yield (string) $status => [$status, false, true];
        }
        foreach ([301, 307, 402, 408, 409, 410, 429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status, false, false];
        }
    }

    #[DataProvider('statuses')]
    public function testOnlyRouteMissingStatusesFallBackAndOnlyProofIsLearned(int $status, bool $fallback, bool $proof): void
    {
        $resolver = new IngestPathResolver();

        self::assertTrue($resolver->isProbing($this->dsn()));
        self::assertTrue($resolver->useVersioned($this->dsn()));
        self::assertSame($fallback, IngestPaths::isRouteMissing($status));
        self::assertSame($proof, IngestPaths::provesRoute($status));
        self::assertSame($fallback, $resolver->versionedAnswered($this->dsn(), $status));
        // LO-M1: 408 / 429 / 5xx from a proxy or an outage teach nothing; only a Beacon answer pins the versioned path.
        self::assertSame(!$proof, $resolver->isProbing($this->dsn()));
        self::assertTrue($resolver->useVersioned($this->dsn()));
    }

    public function testAuditPocTransientThenOldBeaconIsDeliveredThroughLegacy(): void
    {
        // Audit LO PoC A: 503 on the first probe, then the 404 of a Beacon < 1.147.
        $resolver = new IngestPathResolver();

        self::assertFalse($resolver->versionedAnswered($this->dsn(), 503));
        self::assertTrue($resolver->versionedAnswered($this->dsn(), 404), 'the next 404 still falls back');
        $resolver->legacyAnswered($this->dsn(), 202);
        self::assertFalse($resolver->useVersioned($this->dsn()));
    }

    public function testKnownVersionedTargetSelfHealsOnA404(): void
    {
        // Audit LO: 404 after the versioned path was learned (Beacon downgrade, stale entry).
        $resolver = new IngestPathResolver();
        $resolver->versionedAnswered($this->dsn(), 202);
        self::assertFalse($resolver->isProbing($this->dsn()));

        self::assertTrue($resolver->versionedAnswered($this->dsn(), 404), 'resend once to the legacy path');
        self::assertTrue($resolver->isProbing($this->dsn()), 'the entry is forgotten');
        $resolver->legacyAnswered($this->dsn(), 202);
        self::assertFalse($resolver->useVersioned($this->dsn()));
    }

    public function testLegacyAnswersOnlyTeachFromProof(): void
    {
        $resolver = new IngestPathResolver();
        self::assertTrue($resolver->mayFallBack());

        $resolver->versionedAnswered($this->dsn(), 404);
        $resolver->legacyAnswered($this->dsn(), 503);
        self::assertTrue($resolver->isProbing($this->dsn()), 'a transient legacy answer teaches nothing');

        $resolver->legacyAnswered($this->dsn(), 401);
        self::assertFalse($resolver->useVersioned($this->dsn()), 'a Beacon 401 proves the legacy route');

        $resolver->versionedAnswered($this->dsn(), 200);
        self::assertTrue($resolver->useVersioned($this->dsn()), 'the newest answer wins');
        self::assertSame(1, $resolver->count());
    }

    public function testLegacyAnswerIsRememberedPerTarget(): void
    {
        $resolver = new IngestPathResolver(IngestPathMode::Auto);

        self::assertTrue($resolver->versionedAnswered($this->dsn(), 404));
        $resolver->legacyAnswered($this->dsn(), 202);

        self::assertFalse($resolver->isProbing($this->dsn()));
        self::assertFalse($resolver->useVersioned($this->dsn()));
        self::assertTrue($resolver->useVersioned($this->dsn('https://pk:sk@other.example.com/5')), 'other origins are still probed');
        // A request already started on the versioned path still falls back; the legacy answer is learned again.
        self::assertTrue($resolver->versionedAnswered($this->dsn(), 404));
        $resolver->legacyAnswered($this->dsn(), 202);
        self::assertFalse($resolver->useVersioned($this->dsn()));
        self::assertSame(1, $resolver->count());
    }

    public function testBothPathsMissingKeepsTheVersionedPathAndStopsProbing(): void
    {
        $resolver = new IngestPathResolver('auto');

        self::assertTrue($resolver->versionedAnswered($this->dsn(), 404));
        $resolver->legacyAnswered($this->dsn(), 404);

        self::assertFalse($resolver->isProbing($this->dsn()));
        self::assertTrue($resolver->useVersioned($this->dsn()));
        // A later 404 on the kept versioned path self-heals into one more legacy attempt (also covers LO-L1).
        self::assertTrue($resolver->versionedAnswered($this->dsn(), 404));
    }

    public function testForcedModesNeverProbeNorLearn(): void
    {
        $versioned = new IngestPathResolver(IngestPathMode::Versioned);
        $legacy    = new IngestPathResolver('legacy');
        self::assertFalse($versioned->mayFallBack());
        self::assertFalse($legacy->mayFallBack());

        self::assertSame(IngestPathMode::Versioned, $versioned->mode());
        self::assertSame(IngestPathMode::Legacy, $legacy->mode());
        self::assertTrue($versioned->useVersioned($this->dsn()));
        self::assertFalse($legacy->useVersioned($this->dsn()));
        self::assertFalse($versioned->isProbing($this->dsn()));
        self::assertFalse($versioned->versionedAnswered($this->dsn(), 404));
        $versioned->legacyAnswered($this->dsn(), 200);
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
        $resolver->versionedAnswered($this->dsn(), 404);
        $resolver->legacyAnswered($this->dsn(), 200);
        self::assertFalse($resolver->useVersioned($this->dsn()));

        $resolver->reset();

        self::assertSame(0, $resolver->count());
        self::assertTrue($resolver->isProbing($this->dsn()));
        self::assertTrue($resolver->useVersioned($this->dsn()));
    }

    public function testTheMapIsBoundedAndMemoryStaysFlatOverManyOrigins(): void
    {
        // FrankenPHP worker mode without kernel reset: the same instance serves thousands of requests.
        $resolver = new IngestPathResolver();
        for ($i = 0; $i < 500; ++$i) {
            $warm = $this->dsn('https://pk:sk@warm-' . $i . '.example.com/5');
            $resolver->versionedAnswered($warm, 404);
            $resolver->legacyAnswered($warm, 200);
        }
        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 0; $i < 5000; ++$i) {
            $target = $this->dsn('https://pk:sk@host-' . $i . '.example.com/' . ($i + 1));
            if ($resolver->versionedAnswered($target, 404)) {
                $resolver->legacyAnswered($target, 202);
            }
            self::assertLessThanOrEqual(IngestPathResolver::MAX_ENTRIES, $resolver->count());
        }
        gc_collect_cycles();

        self::assertSame(IngestPathResolver::MAX_ENTRIES, $resolver->count());
        self::assertLessThan(64 * 1024, memory_get_usage() - $before);
        self::assertTrue($resolver->isProbing($this->dsn('https://pk:sk@host-0.example.com/1')), 'oldest targets were dropped');
        self::assertFalse($resolver->useVersioned($this->dsn('https://pk:sk@host-4999.example.com/5000')));
    }

    public function testTargetsAreKeptPerOriginAndProject(): void
    {
        $resolver = new IngestPathResolver();
        $known    = $this->dsn('https://pk:sk@old.example.com/1');
        $unknown  = $this->dsn('https://pk:sk@old.example.com/2');

        $resolver->versionedAnswered($known, 404);
        $resolver->legacyAnswered($known, 202);
        // An unknown project on the same older Beacon answers 404 on both paths: it must not pin the other project.
        self::assertTrue($resolver->versionedAnswered($unknown, 404));
        $resolver->legacyAnswered($unknown, 404);

        self::assertFalse($resolver->useVersioned($known), 'the project that works keeps the legacy path');
        self::assertTrue($resolver->useVersioned($unknown));
        self::assertSame(2, $resolver->count());
        self::assertTrue($resolver->isProbing($this->dsn('https://pk:sk@old.example.com/3')), 'a third project is probed on its own');
        self::assertTrue($resolver->isProbing($this->dsn('https://pk:sk@old.example.com:8443/1')), 'another port is another origin');
        self::assertTrue($resolver->isProbing($this->dsn('http://pk:sk@old.example.com/1')), 'another scheme is another origin');
        // Keys and secrets are not part of the key.
        self::assertFalse($resolver->useVersioned($this->dsn('https://other-key:other-secret@old.example.com/1')));
    }

    public function testMaxOriginsIsADeprecatedAliasOfMaxEntries(): void
    {
        // Read by name: the deprecated alias must keep the value of MAX_ENTRIES for code written against #43's first draft.
        self::assertSame(32, constant(IngestPathResolver::class . '::MAX_ENTRIES'));
        self::assertSame(IngestPathResolver::MAX_ENTRIES, constant(IngestPathResolver::class . '::MAX_ORIGINS'));
    }

    private function dsn(string $dsn = 'https://pk:sk@beacon.example.com:9444/5'): BeaconDsn
    {
        return (new BeaconDsnParser())->parse($dsn);
    }
}
