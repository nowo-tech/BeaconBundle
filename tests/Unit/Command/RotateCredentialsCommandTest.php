<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

use function fopen;
use function fwrite;
use function preg_replace;
use function rewind;

use const DATE_ATOM;

#[CoversClass(RotateCredentialsCommand::class)]
#[CoversClass(BeaconConnectionTester::class)]
final class RotateCredentialsCommandTest extends TestCase
{
    private MockClock $clock;

    private InMemoryCredentialsStore $store;

    private ScriptedIngest $ingest;

    protected function setUp(): void
    {
        $this->clock  = new MockClock(new DateTimeImmutable('2026-11-12T10:00:00+00:00'));
        $this->store  = new InMemoryCredentialsStore();
        $this->ingest = new ScriptedIngest(['fixture-secret-env', 'fixture-secret-db', 'fixture-secret-next']);
    }

    public function testWithoutAStoreItExplainsAndFails(): void
    {
        $tester = new CommandTester(new RotateCredentialsCommand($this->resolver(), $this->tester(), new BeaconDsnParser()));

        self::assertSame(Command::FAILURE, $tester->execute(['--dsn' => true]));
        self::assertStringContainsString('No writable credentials store', $this->display($tester));
    }

    public function testOptionsAreValidated(): void
    {
        $tester = $this->command();
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('Pass --dsn and/or --worker-key.', $this->display($tester));

        foreach (['-1', 'abc', '2592001'] as $grace) {
            self::assertSame(Command::INVALID, $tester->execute(['--dsn' => true, '--grace' => $grace]));
        }

        self::assertSame(0, $this->store->saves);
    }

    public function testRotatingTheDsnFromTheConfigurationKeepsItAsPreviousAndSelfTests(): void
    {
        $tester = $this->command();
        $tester->setInputs([F::DB_DSN]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--dsn' => true, '--grace' => '3600']));
        $record = $this->store->current();
        self::assertSame(F::DB_DSN, $record?->dsn());
        self::assertNull($record->workerKey(), 'the worker key stays with the configuration');
        self::assertSame(F::CONFIG_DSN, $record->previousDsn());
        self::assertSame('2026-11-12T11:00:00+00:00', $record->previousDsnExpiresAt()?->format(DATE_ATOM));
        self::assertSame(['pub-db'], $this->ingest->publicKeys(), 'one test Envelope with the new DSN');
        self::assertStringContainsString('Beacon credentials rotated (DSN)', $this->display($tester));
        self::assertStringContainsString('pub-db', $this->display($tester));
        $this->assertNoSecretIn($this->display($tester));
    }

    public function testRotatingBothNonInteractivelyFromStdinWithoutGrace(): void
    {
        $this->store->record = new BeaconCredentials(F::DB_DSN, F::DB_KEY, F::CONFIG_DSN, new DateTimeImmutable('2026-11-12T11:00:00+00:00'));
        $stream              = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, F::NEXT_DSN . "\n" . F::NEXT_KEY . "\n");
        rewind($stream);
        $input = new ArrayInput(['--dsn' => true, '--worker-key' => true, '--grace' => '0']);
        $input->setStream($stream);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $command = new RotateCredentialsCommand($this->resolver(), $this->tester(), new BeaconDsnParser(), $this->store);
        self::assertSame(Command::SUCCESS, $command->run($input, $output));
        $record = $this->store->current();
        self::assertSame(F::NEXT_DSN, $record?->dsn());
        self::assertSame(F::NEXT_KEY, $record->workerKey());
        self::assertNull($record->previousDsn(), 'grace 0: the replaced value is dropped');
        self::assertNull($record->previousWorkerKey());
        $display = $output->fetch();
        self::assertStringContainsString('DSN and worker key', $display);
        $this->assertNoSecretIn($display);
    }

    public function testRotatingOnlyTheWorkerKeyKeepsTheStoredDsnAndItsPrevious(): void
    {
        $expires             = new DateTimeImmutable('2026-11-12T11:00:00+00:00');
        $this->store->record = new BeaconCredentials(F::DB_DSN, null, F::CONFIG_DSN, $expires);
        $tester              = $this->command();
        $tester->setInputs([F::NEXT_KEY]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--worker-key' => true]));
        $record = $this->store->current();
        self::assertSame(F::DB_DSN, $record?->dsn());
        self::assertSame(F::CONFIG_DSN, $record->previousDsn());
        self::assertSame($expires, $record->previousDsnExpiresAt());
        self::assertSame(F::NEXT_KEY, $record->workerKey());
        self::assertSame(F::CONFIG_KEY, $record->previousWorkerKey(), 'the configured key it replaces');
        self::assertSame('2026-11-13T10:00:00+00:00', $record->previousWorkerKeyExpiresAt()?->format(DATE_ATOM), 'default grace: one day');
        self::assertSame([], $this->ingest->calls, 'no test Envelope for a worker key rotation');
        $this->assertNoSecretIn($this->display($tester));
    }

    public function testInvalidOrSameOrForeignValuesAreRefused(): void
    {
        $cases = [
            ['--dsn', 'not a dsn', 'Invalid new Beacon DSN'],
            ['--dsn', F::OTHER_PROJECT_DSN, 'another Beacon origin or project'],
            ['--dsn', F::CONFIG_DSN, 'The new DSN is the current one.'],
            ['--worker-key', 'bwk_short', 'Invalid new worker key'],
            ['--worker-key', F::CONFIG_KEY, 'The new worker key is the current one.'],
        ];

        foreach ($cases as [$option, $value, $message]) {
            $tester = $this->command();
            $tester->setInputs([$value]);
            self::assertSame(Command::INVALID, $tester->execute([$option => true]), $message);
            self::assertStringContainsString($message, $this->display($tester));
            $this->assertNoSecretIn($this->display($tester));
        }

        self::assertSame(0, $this->store->saves);
    }

    public function testAFailedSelfTestRestoresTheOldRecord(): void
    {
        $old                           = new BeaconCredentials(F::DB_DSN, F::DB_KEY);
        $this->store->record           = $old;
        $this->ingest->acceptedSecrets = ['fixture-secret-db'];
        $tester                        = $this->command();
        $tester->setInputs([F::NEXT_DSN]);

        self::assertSame(Command::FAILURE, $tester->execute(['--dsn' => true]));
        self::assertSame($old, $this->store->record, 'restored');
        self::assertSame(2, $this->store->saves);
        self::assertStringContainsString('Self-test failed: Beacon ingest rejected authentication (HTTP 401)', $this->display($tester));
        self::assertStringContainsString('The previous credentials were restored.', $this->display($tester));
        $this->assertNoSecretIn($this->display($tester));
    }

    public function testAnEmptyStoreIsClearedAgainOnRollbackAndRollbackFailuresAreReported(): void
    {
        $this->ingest->acceptedSecrets = [];
        $tester                        = $this->command();
        $tester->setInputs([F::DB_DSN]);
        self::assertSame(Command::FAILURE, $tester->execute(['--dsn' => true]));
        self::assertNull($this->store->record);

        $store = new class extends InMemoryCredentialsStore {
            public function save(BeaconCredentials $credentials): void
            {
                parent::save($credentials);
                $this->failSaves = true;
            }
        };
        $tester = new CommandTester(new RotateCredentialsCommand($this->resolver($store), $this->tester($store), new BeaconDsnParser(), $store));
        $tester->setInputs([F::DB_DSN]);
        self::assertSame(Command::FAILURE, $tester->execute(['--dsn' => true]));
        self::assertStringContainsString('Restoring the previous credentials FAILED (RuntimeException)', $this->display($tester));
    }

    public function testStoreReadOrWriteFailuresChangeNothing(): void
    {
        $this->store->failReads = true;
        $tester                 = $this->command();
        $tester->setInputs([F::DB_DSN]);
        self::assertSame(Command::FAILURE, $tester->execute(['--dsn' => true]));
        self::assertStringContainsString('Cannot read the credentials store (RuntimeException).', $this->display($tester));
        $this->assertNoSecretIn($this->display($tester));

        $this->store->failReads = false;
        $this->store->failSaves = true;
        $tester                 = $this->command();
        $tester->setInputs([F::DB_DSN]);
        self::assertSame(Command::FAILURE, $tester->execute(['--dsn' => true]));
        self::assertStringContainsString('Cannot write the credentials store (RuntimeException); nothing changed.', $this->display($tester));
    }

    public function testAStoreThatDoesNotReadBackFailsTheSelfTest(): void
    {
        $store = new class extends InMemoryCredentialsStore {
            public function current(): ?BeaconCredentials
            {
                return null;
            }
        };
        $tester = new CommandTester(new RotateCredentialsCommand($this->resolver($store), $this->tester($store), new BeaconDsnParser(), $store));
        $tester->setInputs([F::DB_DSN]);
        self::assertSame(Command::FAILURE, $tester->execute(['--dsn' => true]));
        self::assertStringContainsString('the stored DSN does not read back', $this->display($tester));

        $tester->setInputs([F::NEXT_KEY]);
        self::assertSame(Command::FAILURE, $tester->execute(['--worker-key' => true]));
        self::assertStringContainsString('the stored worker key does not read back', $this->display($tester));
    }

    private function command(): CommandTester
    {
        return new CommandTester(new RotateCredentialsCommand($this->resolver(), $this->tester(), new BeaconDsnParser(), $this->store));
    }

    private function resolver(?InMemoryCredentialsStore $store = null): BeaconCredentialsResolver
    {
        return new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, F::CONFIG_KEY, $store ?? $this->store, 60, $this->clock);
    }

    private function tester(?InMemoryCredentialsStore $store = null): BeaconConnectionTester
    {
        return new BeaconConnectionTester(new BeaconDsnParser(), $this->ingest->client(), true, F::CONFIG_DSN, credentials: $this->resolver($store));
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function assertNoSecretIn(string $text): void
    {
        foreach (F::secrets() as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }
}
