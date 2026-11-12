<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Credentials;

use DateTimeImmutable;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Messenger\RemoteRetry\CommandResult;
use Nowo\BeaconBundle\Messenger\RemoteRetry\FailedMessageCommandExecutor;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandException;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function in_array;
use function is_string;
use function str_starts_with;

/**
 * Worker key rotation of remote Messenger commands (1.25, credentials provider).
 */
#[CoversClass(RemoteRetryCredentials::class)]
#[CoversClass(FailedMessageCommandExecutor::class)]
#[CoversClass(RemoteCommandClient::class)]
final class RemoteRetryRotationTest extends TestCase
{
    private const DB_DSN = 'https://pub-db:fixture-secret-db@beacon.example.com:9447/' . RemoteRetryFixtures::PROJECT;

    private MockClock $clock;

    private InMemoryCredentialsStore $store;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-17T10:03:00Z'));
        $this->store = new InMemoryCredentialsStore();
    }

    public function testCredentialsFollowTheResolver(): void
    {
        $credentials = $this->credentials();
        self::assertNull($credentials->problem());
        self::assertSame('pub', $credentials->dsn()?->getPublicKey(), 'nothing stored: the configuration');
        self::assertNull($credentials->previousWorkerKey());
        self::assertFalse($credentials->refresh(), 'unchanged');

        $this->store->record = new BeaconCredentials(self::DB_DSN, F::NEXT_KEY);
        $this->clock->sleep(10);
        $dsn = $credentials->dsn();
        self::assertSame('pub-db', $dsn?->getPublicKey());
        self::assertNotNull($credentials->workerKey());
        self::assertTrue($credentials->matchesProject(RemoteRetryFixtures::PROJECT));

        $this->store->record = null;
        $none                = new RemoteRetryCredentials(new BeaconDsnParser(), '', '', $this->resolver('', ''));
        self::assertStringContainsString('BEACON_DSN', (string) $none->problem());
        self::assertNull($none->workerKey(), 'no worker key without a DSN');

        $noKey = new RemoteRetryCredentials(new BeaconDsnParser(), '', '', $this->resolver(RemoteRetryFixtures::DSN, ''));
        self::assertStringContainsString('BEACON_WORKER_KEY', (string) $noKey->problem());

        $legacy = RemoteRetryFixtures::credentials();
        self::assertNull($legacy->previousWorkerKey(), 'without a resolver: never a previous key');
        self::assertFalse($legacy->refresh());
    }

    public function testSignaturesOfThePreviousKeyAreAcceptedUntilItExpires(): void
    {
        // Rotated: current key NEXT, previous key = the fixture key the command is signed with.
        $this->store->record = new BeaconCredentials(self::DB_DSN, F::NEXT_KEY, null, null, RemoteRetryFixtures::WORKER_KEY, new DateTimeImmutable('2026-10-17T10:10:00Z'));
        $executor            = $this->executor();

        self::assertNotSame(CommandResult::REASON_SIGNATURE_INVALID, $executor->execute(RemoteRetryFixtures::command())->reasonCode);

        $this->clock->sleep(3600);
        $result = $executor->execute(RemoteRetryFixtures::command(['id' => 'after-expiry']));
        self::assertSame(CommandResult::REASON_SIGNATURE_INVALID, $result->reasonCode);
    }

    public function testCurrentKeySignaturesStillPass(): void
    {
        $this->store->record = new BeaconCredentials(self::DB_DSN, RemoteRetryFixtures::WORKER_KEY);
        $result              = $this->executor()->execute(RemoteRetryFixtures::command());

        self::assertNotSame(CommandResult::REASON_SIGNATURE_INVALID, $result->reasonCode);
    }

    public function testClaim401IsRetriedOnceWithThePreviousKey(): void
    {
        $this->store->record = new BeaconCredentials(self::DB_DSN, F::NEXT_KEY, null, null, F::DB_KEY, new DateTimeImmutable('2026-10-17T11:00:00Z'));
        $auth                = [];
        $http                = new MockHttpClient(static function (string $method, string $url, array $options) use (&$auth): MockResponse {
            $header = '';
            foreach ($options['headers'] as $line) {
                if (is_string($line) && str_starts_with($line, 'Authorization: ')) {
                    $header = $line;
                }
            }

            $auth[] = $header === 'Authorization: Bearer ' . F::DB_KEY ? 'previous' : 'current';

            return new MockResponse('{"commands":[]}', ['http_code' => $header === 'Authorization: Bearer ' . F::DB_KEY ? 200 : 401]);
        });

        $claim = $this->client($http)->claim([]);
        self::assertSame([], $claim->commands);
        self::assertSame(['current', 'previous'], $auth);
    }

    public function testClaim401RefreshesOnceAndRetriesWithTheNewKey(): void
    {
        $this->store->record = new BeaconCredentials(self::DB_DSN, F::DB_KEY);
        $resolver            = $this->resolver(RemoteRetryFixtures::DSN, RemoteRetryFixtures::WORKER_KEY, 3600);
        $credentials         = new RemoteRetryCredentials(new BeaconDsnParser(), '', '', $resolver);
        $credentials->dsn();

        // Rotated elsewhere: this process still has DB_KEY cached.
        $this->store->record = new BeaconCredentials(self::DB_DSN, F::NEXT_KEY);
        $calls               = 0;
        $http                = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}', ['http_code' => in_array('Authorization: Bearer ' . F::NEXT_KEY, $options['headers'], true) ? 200 : 401]);
        });
        $client = new RemoteCommandClient($http, $credentials, 'worker', 'prod');

        self::assertSame([], $client->claim([])->commands);
        self::assertSame(2, $calls);

        // Revoked and nothing new: the 401 stands (one refresh per backoff window).
        $this->store->record = new BeaconCredentials(self::DB_DSN, F::DB_KEY);
        $this->clock->sleep(1);
        $denied = new RemoteCommandClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 401])), $credentials, 'worker', 'prod');
        try {
            $denied->claim([]);
            self::fail('Expected RemoteCommandException');
        } catch (RemoteCommandException $e) {
            self::assertSame(RemoteCommandException::KIND_AUTH, $e->kind);
            self::assertStringNotContainsString(F::NEXT_KEY, $e->getMessage());
        }
    }

    public function testRefreshThatLosesTheKeyKeepsThe401(): void
    {
        $this->store->record = new BeaconCredentials(self::DB_DSN, F::DB_KEY);
        $resolver            = $this->resolver(RemoteRetryFixtures::DSN, '', 3600);
        $credentials         = new RemoteRetryCredentials(new BeaconDsnParser(), '', '', $resolver);
        $credentials->dsn();
        $this->store->record = new BeaconCredentials(self::DB_DSN);

        $this->expectException(RemoteCommandException::class);
        (new RemoteCommandClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 401])), $credentials, 'worker', 'prod'))->claim([]);
    }

    private function resolver(string $dsn, string $key, int $ttl = 0): BeaconCredentialsResolver
    {
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), $dsn, $key, $this->store, $ttl, $this->clock);
        $resolver->arm();

        return $resolver;
    }

    private function credentials(): RemoteRetryCredentials
    {
        return new RemoteRetryCredentials(new BeaconDsnParser(), 'ignored', 'ignored', $this->resolver(RemoteRetryFixtures::DSN, RemoteRetryFixtures::WORKER_KEY));
    }

    private function client(MockHttpClient $http): RemoteCommandClient
    {
        return new RemoteCommandClient($http, $this->credentials(), 'worker', 'prod');
    }

    private function executor(): FailedMessageCommandExecutor
    {
        $failed = new InMemoryTransport();

        return new FailedMessageCommandExecutor(
            $this->credentials(),
            new ServiceLocator(['failed' => static fn (): InMemoryTransport => $failed]),
            ['retry', 'discard'],
            ['failed'],
            null,
            $this->clock,
        );
    }
}
