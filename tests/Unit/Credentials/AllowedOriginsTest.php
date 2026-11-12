<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Credentials;

use DateTimeImmutable;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Credentials\ResolvedBeaconCredentials;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function preg_replace;

/**
 * Owner decision 2026-11-12 (1.25.1): a provider DSN wins only when its origin is allowed by configuration.
 */
#[CoversClass(BeaconCredentialsResolver::class)]
#[CoversClass(RotateCredentialsCommand::class)]
final class AllowedOriginsTest extends TestCase
{
    private MockClock $clock;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->clock  = new MockClock(new DateTimeImmutable('2026-11-12T10:00:00+00:00'));
        $this->logger = new ArrayLogger();
    }

    public function testAnAttackerOriginIsRefusedAndEventsGoToTheConfiguredDsn(): void
    {
        $ingest   = new ScriptedIngest(['fixture-secret-env', 'fixture-secret-evil']);
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::ATTACKER_DSN, F::DB_KEY));
        $resolver = $this->resolver(F::CONFIG_DSN, $store);
        $http     = $ingest->client();
        $client   = (new BeaconClientFactory(new BeaconDsnParser(), $http, $this->logger, syncHttpClient: $http))->createResolving($resolver, 'prod', null, 'h', true, 1.0);

        $client->captureMessage('one');
        $client->captureMessage('two');

        self::assertSame(['pub-env', 'pub-env'], $ingest->publicKeys(), 'never sent to the planted origin');
        foreach ($ingest->calls as $call) {
            self::assertStringStartsWith('https://beacon.example.invalid/', $call['url']);
        }

        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $resolver->resolve()->dsnSource);
        self::assertSame(['The Beacon DSN of the credentials provider targets https://attacker.example.invalid, which is not in nowo_beacon.credentials_allowed_origins; using the configured DSN.'], $this->logger->messages('warning'));
        self::assertSame(ResolvedBeaconCredentials::SOURCE_PROVIDER, $resolver->resolve()->workerKeySource, 'the stored key still pairs with the configured DSN');
    }

    public function testDefaultsAllowOnlyTheConfiguredOrigin(): void
    {
        $sameOrigin = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN)));
        self::assertSame('pub-db', $sameOrigin->resolve()->dsn()?->getPublicKey());

        $otherPort = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::OTHER_PORT_DSN)));
        self::assertSame('pub-env', $otherPort->resolve()->dsn()?->getPublicKey(), 'exact origin: another port is another origin');

        $nothingConfigured = $this->resolver('', new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN)));
        self::assertNull($nothingConfigured->resolve()->dsn(), 'no configured DSN and no list: every provider DSN is refused');
        self::assertFalse($nothingConfigured->isOriginAllowed('https://beacon.example.invalid'));
    }

    public function testAnExplicitlyAllowedSecondOriginWorks(): void
    {
        $ingest   = new ScriptedIngest(['fixture-secret-port']);
        $resolver = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::OTHER_PORT_DSN)), ['https://beacon.example.invalid:8443']);
        $http     = $ingest->client();
        $client   = (new BeaconClientFactory(new BeaconDsnParser(), $http, $this->logger, syncHttpClient: $http))->createResolving($resolver, 'prod', null, 'h', true, 1.0);

        $client->captureMessage('moved');
        self::assertSame(['pub-port'], $ingest->publicKeys());
        self::assertTrue($resolver->isOriginAllowed('https://beacon.example.invalid'), 'the configured origin stays allowed');

        $listOnly = $this->resolver('', new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN)), ['https://beacon.example.invalid']);
        self::assertSame('pub-db', $listOnly->resolve()->dsn()?->getPublicKey());
    }

    public function testThePreviousDsnCannotLeaveTheAllowedOrigins(): void
    {
        $expires  = new DateTimeImmutable('2026-11-13T10:00:00+00:00');
        $resolver = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::ATTACKER_DSN, null, F::ATTACKER_DSN, $expires)));

        self::assertNull($resolver->previousDsn(), 'current refused → configured DSN → the planted previous targets another origin');
    }

    public function testTheRotateCommandRefusesANewDsnOutsideTheList(): void
    {
        $store    = new InMemoryCredentialsStore();
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, F::CONFIG_KEY, $store, 60, $this->clock, null, ['https://beacon.example.invalid:8443']);
        $tester   = new CommandTester(new RotateCredentialsCommand($resolver, new BeaconConnectionTester(new BeaconDsnParser(), (new ScriptedIngest([]))->client(), true, F::CONFIG_DSN), new BeaconDsnParser(), $store));

        $tester->setInputs([F::ATTACKER_DSN]);
        self::assertSame(Command::INVALID, $tester->execute(['--dsn' => true]));
        self::assertStringContainsString('targets https://attacker.example.invalid, which is not in nowo_beacon.credentials_allowed_origins', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame(0, $store->saves);
        self::assertStringNotContainsString('fixture-secret-evil', $tester->getDisplay());
    }

    /**
     * @param list<string> $allowed
     */
    private function resolver(string $configDsn, InMemoryCredentialsStore $store, array $allowed = []): BeaconCredentialsResolver
    {
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), $configDsn, F::CONFIG_KEY, $store, 0, $this->clock, $this->logger, $allowed);
        $resolver->arm();

        return $resolver;
    }
}
