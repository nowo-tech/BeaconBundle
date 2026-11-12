<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Credentials;

use DateTimeImmutable;
use Nowo\BeaconBundle\Bridge\InstanceSettings\BeaconSettingsSection;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Credentials\ResolvedBeaconCredentials;
use Nowo\BeaconBundle\Credentials\RotatingEnvelopeTransport;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandException;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function implode;
use function print_r;
use function str_contains;

/**
 * Fixes of audit NS on 1.25.0 (#46).
 */
#[CoversClass(BeaconCredentialsResolver::class)]
#[CoversClass(RotatingEnvelopeTransport::class)]
#[CoversClass(BeaconSettingsSection::class)]
final class AuditNsTest extends TestCase
{
    private const ALLOWED = ['https://attacker.example.invalid', 'https://beacon.example.invalid:8443', 'https://beacon.example.invalid'];

    private MockClock $clock;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->clock  = new MockClock(new DateTimeImmutable('2026-11-12T10:00:00+00:00'));
        $this->logger = new ArrayLogger();
    }

    /**
     * NS-M1 probe: a database writer plants a DSN on another host and no worker key; the configured BEACON_WORKER_KEY
     * must never reach that host (it would let the attacker sign retry / discard commands).
     */
    public function testTheConfiguredWorkerKeyNeverFollowsAProviderDsnToAnotherOrigin(): void
    {
        foreach ([F::ATTACKER_DSN, F::OTHER_PORT_DSN] as $planted) {
            $this->logger->records = [];
            $store                 = new InMemoryCredentialsStore(new BeaconCredentials($planted));
            $resolver              = $this->resolver(F::CONFIG_DSN, $store);
            $resolved              = $resolver->resolve();

            self::assertSame(ResolvedBeaconCredentials::SOURCE_PROVIDER, $resolved->dsnSource, 'events follow the database (owner-accepted)');
            self::assertNull($resolved->workerKey());
            self::assertSame(ResolvedBeaconCredentials::SOURCE_NONE, $resolved->workerKeySource);
            self::assertStringContainsString('store the worker key with the DSN', (string) $resolved->workerKeyProblem());
            $resolver->resolve();
            self::assertCount(1, $this->logger->messages('warning'));

            $requests = [];
            $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = $url . ' ' . implode(' ', $options['headers']);

                return new MockResponse('{}');
            });
            try {
                (new RemoteCommandClient($http, new RemoteRetryCredentials(new BeaconDsnParser(), '', '', $resolver), 'w', 'prod'))->claim([]);
                self::fail('remote commands must be off');
            } catch (RemoteCommandException $e) {
                self::assertSame(RemoteCommandException::KIND_AUTH, $e->kind);
            }

            self::assertSame([], $requests, 'nothing is sent anywhere, least of all the key');
        }
    }

    public function testTheConfiguredWorkerKeyStaysUsableOnTheSameOriginAndWithoutConfiguredDsnNever(): void
    {
        $sameOrigin = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN)))->resolve();
        self::assertSame(ResolvedBeaconCredentials::SOURCE_CONFIG, $sameOrigin->workerKeySource, 'same scheme, host and port: a secret rotation');

        $noConfiguredDsn = $this->resolver('', new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN)))->resolve();
        self::assertNull($noConfiguredDsn->workerKey(), 'no trusted origin to compare with');

        $invalidConfiguredDsn = $this->resolver('not a dsn', new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN)))->resolve();
        self::assertNull($invalidConfiguredDsn->workerKey());

        $pairedInDatabase = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::ATTACKER_DSN, F::DB_KEY)))->resolve();
        self::assertSame(ResolvedBeaconCredentials::SOURCE_PROVIDER, $pairedInDatabase->workerKeySource, 'a key stored with the DSN goes with it');
    }

    public function testThePreviousWorkerKeyFollowsTheSameOriginRule(): void
    {
        $expires = new DateTimeImmutable('2026-11-13T10:00:00+00:00');

        $planted = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::ATTACKER_DSN, F::DB_KEY, null, null, F::CONFIG_KEY, $expires)));
        self::assertNull($planted->previousWorkerKey());
        self::assertContains('The previous Beacon worker key is not used with a provider DSN that targets another origin than nowo_beacon.dsn: it is ignored.', $this->logger->messages('warning'));

        $same = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN, F::DB_KEY, null, null, F::CONFIG_KEY, $expires)));
        self::assertNotNull($same->previousWorkerKey());

        $databaseOnly = $this->resolver('', new InMemoryCredentialsStore(new BeaconCredentials(F::DB_DSN, F::DB_KEY, null, null, F::NEXT_KEY, $expires)));
        self::assertNotNull($databaseOnly->previousWorkerKey(), 'no configured DSN: nothing of the configuration can be in the record');

        $configDsn = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(null, null, null, null, F::NEXT_KEY, $expires)));
        self::assertNotNull($configDsn->previousWorkerKey());
        self::assertStringNotContainsString('fixture-secret', print_r($this->logger->records, true));
    }

    /**
     * NS-L1: a previous value expiring more than 30 days ahead (e.g. 2099) is ignored.
     */
    public function testAPreviousValueExpiringTooLateIsIgnored(): void
    {
        $far      = new DateTimeImmutable('2099-01-01T00:00:00+00:00');
        $resolver = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::NEXT_DSN, F::NEXT_KEY, F::DB_DSN, $far, F::DB_KEY, $far)));

        self::assertNull($resolver->previousDsn());
        self::assertNull($resolver->previousWorkerKey());
        self::assertContains('The previous Beacon DSN expires more than 30 days from now: it is ignored.', $this->logger->messages('warning'));
        self::assertContains('The previous Beacon worker key expires more than 30 days from now: it is ignored.', $this->logger->messages('warning'));

        $limit = $this->clock->now()->modify('+' . BeaconCredentialsResolver::MAX_PREVIOUS_LIFETIME . ' seconds');
        $ok    = $this->resolver(F::CONFIG_DSN, new InMemoryCredentialsStore(new BeaconCredentials(F::NEXT_DSN, F::NEXT_KEY, F::DB_DSN, $limit, F::DB_KEY, $limit)));
        self::assertNotNull($ok->previousDsn(), 'exactly 30 days is accepted (the rotate command maximum)');
        self::assertNotNull($ok->previousWorkerKey());
    }

    /**
     * NS-I3: once the previous DSN is rejected too, it is not tried again until the credentials change.
     */
    public function testARejectedPreviousDsnIsSkippedAfterwards(): void
    {
        $ingest   = new ScriptedIngest([]);
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::NEXT_DSN, null, F::DB_DSN, new DateTimeImmutable('2026-11-12T11:00:00+00:00')));
        $resolver = $this->resolver(F::CONFIG_DSN, $store, 3600);
        $http     = $ingest->client();
        $client   = (new BeaconClientFactory(new BeaconDsnParser(), $http, $this->logger, syncHttpClient: $http))->createResolving($resolver, 'prod', null, 'h', true, 1.0);

        $client->captureMessage('one');
        self::assertSame(['pub-next', 'pub-db'], $ingest->publicKeys());

        $ingest->calls = [];
        $client->captureMessage('two');
        self::assertSame(['pub-next'], $ingest->publicKeys(), 'previous skipped, refresh within backoff');

        $ingest->acceptedSecrets = ['fixture-secret-db'];
        $store->record           = new BeaconCredentials(F::NEXT_DSN, null, F::DB_DSN, new DateTimeImmutable('2026-11-12T11:30:00+00:00'));
        $resolver->invalidate();
        $ingest->calls = [];
        $client->captureMessage('three');
        self::assertSame(['pub-next', 'pub-db'], $ingest->publicKeys(), 'new credentials version: the previous DSN is tried again');
    }

    /**
     * NS-L3 / NS-L1: bridge fields are never exported or imported; expiries are read-only in the form.
     */
    public function testBridgeFieldsAreNotPortableAndExpiriesReadOnly(): void
    {
        foreach ((new BeaconSettingsSection())->getFields() as $field) {
            self::assertInstanceOf(SettingField::class, $field);
            self::assertFalse($field->portable, $field->name);
            $readOnly = ($field->formOptions['attr']['readonly'] ?? null) === 'readonly';
            self::assertSame(str_contains($field->name, 'expires_at'), $readOnly, $field->name);
        }
    }

    private function resolver(string $configDsn, InMemoryCredentialsStore $store, int $ttl = 0): BeaconCredentialsResolver
    {
        // The host allows the attacker origins here: the NS-M1 pairing rule must hold on its own (defence in depth).
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), $configDsn, F::CONFIG_KEY, $store, $ttl, $this->clock, $this->logger, self::ALLOWED);
        $resolver->arm();

        return $resolver;
    }
}
