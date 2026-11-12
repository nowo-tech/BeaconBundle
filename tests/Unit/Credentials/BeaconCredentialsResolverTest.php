<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Credentials;

use ArrayObject;
use DateTimeImmutable;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Credentials\ResolvedBeaconCredentials;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function print_r;
use function serialize;
use function var_export;

#[CoversClass(BeaconCredentialsResolver::class)]
#[CoversClass(ResolvedBeaconCredentials::class)]
#[CoversClass(BeaconCredentials::class)]
final class BeaconCredentialsResolverTest extends TestCase
{
    private MockClock $clock;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->clock  = new MockClock(new DateTimeImmutable('2026-11-12T10:00:00+00:00'));
        $this->logger = new ArrayLogger();
    }

    public function testWithoutProviderTheConfigurationIsUsedAndNeverReparsed(): void
    {
        $resolver = $this->resolver(null);
        $resolver->arm();

        $first = $resolver->resolve();
        self::assertFalse($resolver->hasProvider());
        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $first->dsnSource);
        self::assertSame('pub-env', $first->dsn()?->getPublicKey());
        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $first->workerKeySource);
        self::assertInstanceOf(WorkerKey::class, $first->workerKey());

        $this->clock->sleep(3600);
        $resolver->reset();
        $resolver->invalidate();
        self::assertSame($first, $resolver->resolve(), 'the configuration never changes: one snapshot per process');
        self::assertFalse($resolver->refresh());
    }

    public function testProviderValuesWinOnceArmedAndBootTimeUsesTheConfiguration(): void
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN, F::DB_KEY));
        $resolver = $this->resolver($store);

        $boot = $resolver->resolve();
        self::assertSame(0, $store->reads, 'not armed: the provider is never read while the kernel boots');
        self::assertSame('pub-env', $boot->dsn()?->getPublicKey());

        $resolver->arm();
        $resolver->arm();
        $live = $resolver->resolve();
        self::assertSame(1, $store->reads);
        self::assertSame(ResolvedBeaconCredentials::SOURCE_PROVIDER, $live->dsnSource);
        self::assertSame('pub-db', $live->dsn()?->getPublicKey());
        self::assertSame(ResolvedBeaconCredentials::SOURCE_PROVIDER, $live->workerKeySource);
        self::assertSame(F::DB_KEY, $live->effective()->workerKey());
        self::assertGreaterThan($boot->generation, $live->generation);
    }

    public function testNullRecordOrNullFieldsFallBackToTheConfiguration(): void
    {
        $store    = new InMemoryCredentialsStore();
        $resolver = $this->armed($store);
        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $resolver->resolve()->dsnSource);

        $store->record = new BeaconCredentials(null, F::DB_KEY);
        $resolver->invalidate();
        $resolved = $resolver->resolve();
        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $resolved->dsnSource);
        self::assertSame(ResolvedBeaconCredentials::SOURCE_PROVIDER, $resolved->workerKeySource);
    }

    public function testProviderFailureFallsBackWarnsOnceWithoutTheMessageAndWarnsAgainAfterRecovery(): void
    {
        $store            = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN));
        $store->failReads = true;
        $resolver         = $this->armed($store, 0);

        for ($i = 0; $i < 3; ++$i) {
            $resolved = $resolver->resolve();
            self::assertSame('pub-env', $resolved->dsn()?->getPublicKey());
        }

        self::assertSame(3, $store->reads);
        self::assertSame(['Beacon credentials provider failed (RuntimeException); using the configured credentials.'], $this->logger->messages('warning'));
        self::assertTrue($this->logger->records[0]['context'][EnvelopeTransport::LOG_CONTEXT_FLAG]);

        $store->failReads = false;
        self::assertSame('pub-db', $resolver->resolve()->dsn()?->getPublicKey());
        $store->failReads = true;
        $resolver->resolve();
        self::assertCount(2, $this->logger->messages('warning'), 'a new failure streak warns again');
        $this->assertNoSecretLogged();
    }

    public function testInvalidProviderValuesFallBackToTheConfigurationWithOneWarning(): void
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials('ftp://pub:fixture-secret-db@beacon.example.invalid/1', 'not-a-key'));
        $resolver = $this->armed($store, 0);
        $resolver->resolve();
        $resolved = $resolver->resolve();

        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $resolved->dsnSource);
        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $resolved->workerKeySource);
        self::assertSame([
            'The Beacon DSN of the credentials provider is invalid (Unsupported DSN scheme "ftp". Use http or https.); using the configured DSN.',
            'The Beacon worker key of the credentials provider is not a "bwk_…" key; using the configured worker key.',
        ], $this->logger->messages('warning'));
        $this->assertNoSecretLogged();
    }

    public function testNoDsnAnywhereGivesAProblemAndNoTransportMaterial(): void
    {
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), '', '', new InMemoryCredentialsStore(), 60, $this->clock);
        $resolver->arm();
        $resolved = $resolver->resolve();

        self::assertNull($resolved->dsn());
        self::assertSame(ResolvedBeaconCredentials::SOURCE_NONE, $resolved->dsnSource);
        self::assertStringContainsString('BEACON_DSN', (string) $resolved->dsnProblem());
        self::assertNull($resolved->workerKey());
        self::assertStringContainsString('BEACON_WORKER_KEY', (string) $resolved->workerKeyProblem());

        $invalid = new BeaconCredentialsResolver(new BeaconDsnParser(), 'nonsense', 'bwk_short', null, 60, $this->clock);
        self::assertStringStartsWith('Invalid Beacon DSN: ', (string) $invalid->resolve()->dsnProblem());
        self::assertStringStartsWith('Invalid Beacon worker key', (string) $invalid->resolve()->workerKeyProblem());
        self::assertSame(ResolvedBeaconCredentials::SOURCE_NONE, $invalid->resolve()->dsnSource);
    }

    public function testCacheTtlResetAndInvalidate(): void
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN));
        $resolver = $this->armed($store, 60);
        self::assertSame(60, $resolver->cacheTtl());

        $resolver->resolve();
        $resolver->resolve();
        self::assertSame(1, $store->reads, 'cached within the TTL');

        $this->clock->sleep(61);
        $resolver->resolve();
        self::assertSame(2, $store->reads, 'expired by time (no reset needed: worker with kernel reset false)');

        $resolver->reset();
        $resolver->resolve();
        self::assertSame(3, $store->reads, 'kernel.reset expires the cache');

        $resolver->invalidate();
        $resolver->resolve();
        self::assertSame(4, $store->reads);

        $zero = $this->armed($store, 0);
        $zero->resolve();
        $zero->resolve();
        self::assertSame(6, $store->reads, 'TTL 0 reads on every use');
    }

    public function testGenerationChangesOnlyWhenAValueChanges(): void
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN));
        $resolver = $this->armed($store, 0);

        $generation = $resolver->resolve()->generation;
        self::assertSame($generation, $resolver->resolve()->generation);

        $store->record = new BeaconCredentials(F::NEXT_DSN);
        $next          = $resolver->resolve();
        self::assertSame($generation + 1, $next->generation);
        self::assertSame('pub-next', $next->dsn()?->getPublicKey());
    }

    public function testRefreshHasABackoffAndReportsChanges(): void
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN));
        $resolver = $this->resolver($store);
        self::assertFalse($resolver->refresh(), 'not armed');

        $resolver->arm();
        $resolver->resolve();
        $store->record = new BeaconCredentials(F::NEXT_DSN);
        self::assertTrue($resolver->refresh());
        self::assertSame('pub-next', $resolver->resolve()->dsn()?->getPublicKey());

        $store->record = new BeaconCredentials(F::DB_DSN);
        self::assertFalse($resolver->refresh(), 'backoff');
        $this->clock->sleep(BeaconCredentialsResolver::REFRESH_BACKOFF + 1);
        self::assertTrue($resolver->refresh());
        $this->clock->sleep(BeaconCredentialsResolver::REFRESH_BACKOFF + 1);
        self::assertFalse($resolver->refresh(), 'no change');
    }

    public function testPreviousValuesNeedAnExpiryTheSameTargetAndExpire(): void
    {
        $expires  = new DateTimeImmutable('2026-11-12T11:00:00+00:00');
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::NEXT_DSN, F::NEXT_KEY, F::DB_DSN, $expires, F::DB_KEY, $expires));
        $resolver = $this->armed($store, 0);

        self::assertSame('pub-db', $resolver->previousDsn()?->getPublicKey());
        self::assertInstanceOf(WorkerKey::class, $resolver->previousWorkerKey());
        self::assertSame(F::DB_DSN, $resolver->resolve()->effective()->previousDsn());

        $this->clock->sleep(3600);
        self::assertNull($resolver->previousDsn(), 'expired');
        self::assertNull($resolver->previousWorkerKey(), 'expired');
    }

    public function testRejectedPreviousValuesAreIgnoredWithOneWarningEach(): void
    {
        $expires = new DateTimeImmutable('2026-11-12T11:00:00+00:00');
        $cases   = [
            'The previous Beacon DSN has no expiry: it is ignored.'                                                                                                                                                           => new BeaconCredentials(F::NEXT_DSN, null, F::DB_DSN),
            'The previous Beacon DSN is invalid (DSN secret key is required. Use https://PUBLIC_KEY:SECRET_KEY@host/PROJECT_ID (Symfony Beacon rejects public-key-only auth when the API key has a secret).): it is ignored.' => new BeaconCredentials(F::NEXT_DSN, null, 'https://pub@beacon.example.invalid/1', $expires),
            'The previous Beacon DSN targets another Beacon origin or project than the current one: it is ignored.'                                                                                                           => new BeaconCredentials(F::NEXT_DSN, null, F::OTHER_PROJECT_DSN, $expires),
            'The previous Beacon worker key has no expiry: it is ignored.'                                                                                                                                                    => new BeaconCredentials(F::NEXT_DSN, null, null, null, F::DB_KEY),
            'The previous Beacon worker key is not a "bwk_…" key: it is ignored.'                                                                                                                                             => new BeaconCredentials(F::NEXT_DSN, null, null, null, 'bwk_short', $expires),
        ];

        foreach ($cases as $warning => $record) {
            $this->logger->records = [];
            $resolver              = $this->armed(new InMemoryCredentialsStore($record), 0);
            $resolver->resolve();
            $resolver->resolve();

            self::assertNull($resolver->previousDsn());
            self::assertNull($resolver->previousWorkerKey());
            self::assertSame([$warning], $this->logger->messages('warning'));
        }

        $this->assertNoSecretLogged();
    }

    public function testPreviousDsnIsIgnoredWithoutACurrentDsn(): void
    {
        $expires  = new DateTimeImmutable('2026-11-12T11:00:00+00:00');
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), '', '', new InMemoryCredentialsStore(new BeaconCredentials(null, null, F::DB_DSN, $expires)), 0, $this->clock, $this->logger);
        $resolver->arm();

        self::assertNull($resolver->previousDsn());
    }

    public function testAReEntrantCallWhileTheProviderIsReadNeverRecurses(): void
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN));
        $resolver = $this->armed($store, 0);
        /** @var ArrayObject<int, bool|string|null> $nested */
        $nested = new ArrayObject();

        $store->onRead = static function () use ($resolver, $nested): void {
            // e.g. the provider logs an error, the Monolog handler reports it, the client resolves credentials again
            $nested[] = $resolver->resolve()->dsn()?->getPublicKey();
            $nested[] = $resolver->refresh();
        };

        self::assertSame('pub-db', $this->publicKey($resolver));
        self::assertSame(['pub-env', false], $nested->getArrayCopy(), 'first read: no snapshot yet, the configuration answers');
        self::assertSame(1, $store->reads);

        $nested->exchangeArray([]);
        self::assertSame('pub-db', $this->publicKey($resolver));
        self::assertSame(['pub-db', false], $nested->getArrayCopy(), 'later reads: the last snapshot answers');
        self::assertSame(2, $store->reads);
    }

    public function testNoSecretInDumpsAndNoSerialization(): void
    {
        $expires  = new DateTimeImmutable('2026-11-12T11:00:00+00:00');
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::NEXT_DSN, F::NEXT_KEY, F::DB_DSN, $expires, F::DB_KEY, $expires));
        $resolver = $this->armed($store, 60);
        $resolved = $resolver->resolve();

        $dumps = print_r($resolver, true) . print_r($resolved, true) . print_r($store->record, true) . var_export($resolved->__debugInfo(), true);
        foreach (F::secrets() as $secret) {
            self::assertStringNotContainsString($secret, $dumps);
        }

        self::assertStringContainsString('2026-11-12T11:00:00+00:00', print_r($store->record, true));

        foreach ([$resolved, $store->record] as $object) {
            try {
                serialize($object);
                self::fail('serialization must be refused');
            } catch (Throwable $e) {
                self::assertStringContainsString('cannot be serialized', $e->getMessage());
            }
        }
    }

    public function testCredentialsValueObjectNormalizesBlanks(): void
    {
        $empty = new BeaconCredentials(' ', '', "\n", null, '  ');
        self::assertTrue($empty->isEmpty());
        self::assertNull($empty->dsn());
        self::assertNull($empty->previousWorkerKeyExpiresAt());

        $value = new BeaconCredentials(' ' . F::DB_DSN . ' ');
        self::assertSame(F::DB_DSN, $value->dsn());
        self::assertFalse($value->isEmpty());
    }

    private function resolver(?InMemoryCredentialsStore $store, int $ttl = 60): BeaconCredentialsResolver
    {
        return new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, F::CONFIG_KEY, $store, $ttl, $this->clock, $this->logger);
    }

    private function armed(InMemoryCredentialsStore $store, int $ttl = 60): BeaconCredentialsResolver
    {
        $resolver = $this->resolver($store, $ttl);
        $resolver->arm();

        return $resolver;
    }

    private function publicKey(BeaconCredentialsResolver $resolver): ?string
    {
        return $resolver->resolve()->dsn()?->getPublicKey();
    }

    private function assertNoSecretLogged(): void
    {
        $logged = print_r($this->logger->records, true);
        foreach (F::secrets() as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }
}
