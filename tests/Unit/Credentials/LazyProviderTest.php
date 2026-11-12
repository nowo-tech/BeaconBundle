<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Credentials;

use ArrayObject;
use DateTimeImmutable;
use LogicException;
use Nowo\BeaconBundle\Bridge\InstanceSettings\InstanceSettingsCredentialsStore;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\FakeInstanceSettings;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Clock\MockClock;

use function print_r;

/**
 * 1.25.2: provider, logger and InstanceSettings injected as closures, built on first use.
 */
#[CoversClass(BeaconCredentialsResolver::class)]
#[CoversClass(InstanceSettingsCredentialsStore::class)]
final class LazyProviderTest extends TestCase
{
    public function testTheProviderAndLoggerAreBuiltOnTheFirstReadOnlyOnce(): void
    {
        /** @var ArrayObject<string, int> $built */
        $built    = new ArrayObject(['provider' => 0, 'logger' => 0]);
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN));
        $logger   = new ArrayLogger();
        $resolver = new BeaconCredentialsResolver(
            new BeaconDsnParser(),
            F::CONFIG_DSN,
            '',
            static function () use ($built, $store): InMemoryCredentialsStore {
                ++$built['provider'];

                return $store;
            },
            0,
            new MockClock(new DateTimeImmutable('2026-11-12T10:00:00+00:00')),
            static function () use ($built, $logger): ArrayLogger {
                ++$built['logger'];

                return $logger;
            },
        );

        self::assertTrue($resolver->hasProvider());
        self::assertSame(['provider' => 0, 'logger' => 0], $built->getArrayCopy(), 'nothing built with the resolver');
        self::assertStringContainsString('lazy', print_r($resolver, true));

        $resolver->resolve();
        self::assertSame(0, $built['provider'], 'not armed: no provider');
        $resolver->arm();
        $resolver->resolve();
        $resolver->resolve();
        self::assertSame(1, $built['provider']);
        self::assertSame('pub-db', $resolver->resolve()->dsn()?->getPublicKey());

        $store->record = new BeaconCredentials('nonsense');
        $resolver->resolve();
        self::assertSame(1, $built['logger']);
        self::assertCount(1, $logger->messages('warning'));
    }

    public function testAProviderBuiltWhileReadingSeesTheConfigurationAndAWrongClosureFallsBack(): void
    {
        $resolver = null;
        $nested   = null;
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', static function () use (&$resolver, &$nested): InMemoryCredentialsStore {
            // Building the provider logs through Beacon, which reads the credentials again.
            $nested = $resolver?->resolve()->dsn()?->getPublicKey();

            return new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN));
        }, 0);
        $resolver->arm();
        self::assertSame('pub-db', $resolver->resolve()->dsn()?->getPublicKey());
        self::assertSame('pub-env', $nested);

        $logger = new ArrayLogger();
        $wrong  = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', static fn (): stdClass => new stdClass(), 0, null, static fn (): ?ArrayLogger => null);
        $wrong->arm();
        self::assertSame('pub-env', $wrong->resolve()->dsn()?->getPublicKey(), 'a closure that returns no provider counts as a provider failure');
        self::assertSame([], $logger->records);
    }

    public function testTheBridgeStoreBuildsInstanceSettingsOnFirstUse(): void
    {
        /** @var ArrayObject<int, int> $built */
        $built    = new ArrayObject([0]);
        $settings = new FakeInstanceSettings();
        $store    = new InstanceSettingsCredentialsStore(static function () use ($built, $settings): FakeInstanceSettings {
            ++$built[0];

            return $settings;
        });
        self::assertSame(0, $built[0]);

        $store->save(new BeaconCredentials(F::DB_DSN));
        self::assertSame(F::DB_DSN, $store->current()?->dsn());
        self::assertSame(1, $built[0], 'built once');

        $this->expectException(LogicException::class);
        (new InstanceSettingsCredentialsStore(static fn (): stdClass => new stdClass()))->current();
    }
}
