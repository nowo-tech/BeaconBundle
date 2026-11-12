<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Credentials;

use DateTimeImmutable;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `nowo:beacon:test`, the self-test and the rotate command see the effective DSN when a provider is configured.
 */
#[CoversClass(BeaconConnectionTester::class)]
#[CoversClass(BeaconSelfTest::class)]
#[CoversClass(RotateCredentialsCommand::class)]
#[CoversClass(BeaconCredentialsResolver::class)]
final class ProviderAwareDiagnosticsTest extends TestCase
{
    public function testConnectionTesterProbesTheStoredDsn(): void
    {
        $ingest   = new ScriptedIngest(['fixture-secret-db']);
        $resolver = $this->resolver(new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN)));
        $tester   = new BeaconConnectionTester(new BeaconDsnParser(), $ingest->client(), true, F::CONFIG_DSN, credentials: $resolver);

        $result = $tester->test();
        self::assertTrue($result->isSuccess(), $result->getMessage());
        self::assertSame(['pub-db'], $ingest->publicKeys());

        $withoutProvider = new BeaconConnectionTester(new BeaconDsnParser(), $ingest->client(), true, F::CONFIG_DSN, credentials: new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN));
        self::assertSame('pub-env', $withoutProvider->test(true)->getTarget()['public_key'] ?? null);
    }

    public function testSelfTestStatusShowsTheStoredTarget(): void
    {
        $resolver = $this->resolver(new InMemoryCredentialsStore(new BeaconCredentials(F::OTHER_PROJECT_DSN)));
        $status   = (new BeaconSelfTest(new NullBeaconClient(), new BeaconDsnParser(), F::CONFIG_DSN, credentials: $resolver))->status();

        self::assertSame('2', $status['project_id']);
    }

    public function testRotatingTheWorkerKeyWithoutAnyDsnPrintsNoTarget(): void
    {
        $store    = new InMemoryCredentialsStore();
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), '', F::CONFIG_KEY, $store, 60);
        $tester   = new CommandTester(new RotateCredentialsCommand($resolver, new BeaconConnectionTester(new BeaconDsnParser(), (new ScriptedIngest([]))->client(), true, ''), new BeaconDsnParser(), $store));
        $tester->setInputs([F::NEXT_KEY]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--worker-key' => true]));
        self::assertStringNotContainsString('Origin', $tester->getDisplay());
        self::assertSame(F::NEXT_KEY, $store->record?->workerKey());
    }

    public function testWithoutClockTheSystemTimeIsUsed(): void
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN, null, F::CONFIG_DSN, new DateTimeImmutable('+1 hour')));
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', $store, 60);
        $resolver->arm();

        self::assertSame('pub-db', $resolver->resolve()->dsn()?->getPublicKey());
        self::assertSame('pub-env', $resolver->previousDsn()?->getPublicKey());
        $resolver->resolve();
        self::assertSame(1, $store->reads, 'cached for the TTL by the system clock');
    }

    private function resolver(InMemoryCredentialsStore $store): BeaconCredentialsResolver
    {
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', $store, 60);
        $resolver->arm();

        return $resolver;
    }
}
