<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Credentials;

use DateTimeImmutable;
use LogicException;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Client\ResolvingBeaconClient;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Credentials\ResolvedBeaconCredentials;
use Nowo\BeaconBundle\Credentials\ResolvingEnvelopeTransport;
use Nowo\BeaconBundle\Credentials\RotatingEnvelopeTransport;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessage;
use Nowo\BeaconBundle\Envelope\TransportResult;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function print_r;
use function str_repeat;

#[CoversClass(RotatingEnvelopeTransport::class)]
#[CoversClass(ResolvingEnvelopeTransport::class)]
#[CoversClass(ResolvingBeaconClient::class)]
#[CoversClass(BeaconClientFactory::class)]
#[CoversClass(AsyncEnvelopeTransport::class)]
final class RotationTransportTest extends TestCase
{
    private MockClock $clock;

    private ArrayLogger $logger;

    private InMemoryCredentialsStore $store;

    protected function setUp(): void
    {
        $this->clock  = new MockClock(new DateTimeImmutable('2026-11-12T10:00:00+00:00'));
        $this->logger = new ArrayLogger();
        $this->store  = new InMemoryCredentialsStore();
    }

    public function testNoDsnAnywhereDropsCapturesLikeTheNullClient(): void
    {
        $ingest   = new ScriptedIngest([]);
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), '', '', $this->store, 0, $this->clock, $this->logger);
        $resolver->arm();
        $client = $this->factory($ingest)->createResolving($resolver, 'prod', null, 'h', true, 1.0);

        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureMessage('dropped'));
        self::assertNull($client->captureDeploy(['release' => '1.0']));
        self::assertNull($client->captureCheckIn(['run_id' => str_repeat('a', 32), 'status' => 'in_progress']));
        self::assertNull($client->captureScheduleCatalog([]));
        self::assertNull($client->captureQueueSnapshot([]));
        self::assertSame([], $ingest->calls);
    }

    public function testProviderDsnIsUsedAndAHotChangeRebuildsTheClient(): void
    {
        $ingest              = new ScriptedIngest(['fixture-secret-db', 'fixture-secret-next']);
        $this->store->record = new BeaconCredentials(F::DB_DSN);
        $resolver            = $this->resolver(0);
        $client              = $this->factory($ingest)->createResolving($resolver, 'prod', '1.0', 'h', true, 1.0);

        self::assertTrue($client->isEnabled());
        self::assertNotNull($client->captureMessage('one'));
        $this->store->record = new BeaconCredentials(F::NEXT_DSN);
        self::assertNotNull($client->captureException(new RuntimeException('two')));
        self::assertNotNull($client->captureTransaction('GET /', 1.0, 2.0));
        self::assertNotNull($client->captureDeploy(['release' => '1.0']));
        self::assertNotNull($client->captureCheckIn(['run_id' => str_repeat('a', 32), 'status' => 'in_progress']));
        self::assertNotNull($client->captureScheduleCatalog([]));
        self::assertNotNull($client->captureQueueSnapshot([]));

        self::assertSame(['pub-db', 'pub-next', 'pub-next', 'pub-next', 'pub-next', 'pub-next', 'pub-next'], $ingest->publicKeys());
    }

    public function testBreadcrumbsAndTagsNeverReadTheCredentials(): void
    {
        $breadcrumbs = new BreadcrumbBuffer();
        $scope       = new Scope();
        $resolver    = $this->resolver(0);
        $client      = new ResolvingBeaconClient($resolver, static fn (): BeaconClientInterface => throw new LogicException('not built'), $breadcrumbs, $scope);

        $client->addBreadcrumb('step', 'nav');
        $client->setTag('a', '1');
        $client->setTags(['b' => '2']);
        self::assertSame(['a' => '1', 'b' => '2'], $client->getTags());
        $client->clearTags();
        self::assertSame([], $client->getTags());
        self::assertCount(1, $breadcrumbs->all());
        self::assertSame(0, $this->store->reads);

        $bare = new ResolvingBeaconClient($resolver, static fn (): BeaconClientInterface => new NullBeaconClient());
        $bare->addBreadcrumb('x');
        $bare->setTag('a', '1');
        $bare->setTags([]);
        $bare->clearTags();
        self::assertSame([], $bare->getTags());
    }

    public function testABuildFailureDropsCapturesInsteadOfThrowing(): void
    {
        $client = new ResolvingBeaconClient($this->resolver(0), static fn (): BeaconClientInterface => throw new RuntimeException('boom'));

        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureMessage('x'));
    }

    public function test401WithAPreviousDsnIsResentOnceWithIt(): void
    {
        $ingest              = new ScriptedIngest(['fixture-secret-db']);
        $this->store->record = new BeaconCredentials(F::NEXT_DSN, null, F::DB_DSN, new DateTimeImmutable('2026-11-12T11:00:00+00:00'));
        $client              = $this->factory($ingest)->createResolving($this->resolver(60), 'prod', null, 'h', true, 1.0);

        self::assertNotNull($client->captureMessage('rotating'));
        self::assertSame(['pub-next', 'pub-db'], $ingest->publicKeys());
        self::assertSame([401, 200], [$ingest->calls[0]['status'], $ingest->calls[1]['status']]);
        self::assertContains('Beacon ingest answered HTTP 401 to the current DSN; the Envelope is re-sent once with the previous DSN.', $this->logger->messages('warning'));
        $this->assertNoSecretLogged();

        $this->clock->sleep(7200);
        $ingest->calls = [];
        $client->captureMessage('after expiry');
        self::assertSame(['pub-next'], $ingest->publicKeys(), 'an expired previous DSN is never used');
    }

    public function test401WithoutPreviousRefreshesOnceAndResendsWhenTheDsnChanged(): void
    {
        $ingest              = new ScriptedIngest(['fixture-secret-db', 'fixture-secret-next']);
        $this->store->record = new BeaconCredentials(F::DB_DSN);
        $resolver            = $this->resolver(3600);
        $client              = $this->factory($ingest)->createResolving($resolver, 'prod', null, 'h', true, 1.0);
        $client->captureMessage('warm');
        self::assertSame(['pub-db'], $ingest->publicKeys());

        // Rotated elsewhere (another process, old secret revoked); this one still has the old DSN cached for an hour.
        $this->store->record     = new BeaconCredentials(F::NEXT_DSN);
        $ingest->acceptedSecrets = ['fixture-secret-next'];
        $ingest->calls           = [];
        $client->captureMessage('after rotation');
        self::assertSame(['pub-db', 'pub-next'], $ingest->publicKeys());
        self::assertContains('Beacon ingest answered HTTP 401; the credentials were re-read and the Envelope is re-sent once with the new DSN.', $this->logger->messages('warning'));

        // A 401 with nothing new: one refresh at most per backoff window, then the 401 stands.
        $ingest->acceptedSecrets = [];
        $ingest->calls           = [];
        $client->captureMessage('revoked');
        self::assertSame(['pub-next'], $ingest->publicKeys(), 'refresh within the backoff: no re-read, no re-send');
        $this->clock->sleep(BeaconCredentialsResolver::REFRESH_BACKOFF + 1);
        $ingest->calls = [];
        $client->captureMessage('revoked again');
        self::assertSame(['pub-next'], $ingest->publicKeys(), 'refreshed, unchanged: no re-send');
        $this->assertNoSecretLogged();
    }

    public function testRefreshThatLosesTheDsnKeepsTheRejection(): void
    {
        $ingest              = new ScriptedIngest([]);
        $this->store->record = new BeaconCredentials(F::DB_DSN);
        $resolver            = new BeaconCredentialsResolver(new BeaconDsnParser(), '', '', $this->store, 3600, $this->clock, $this->logger);
        $resolver->arm();
        $transport = $this->factory($ingest)->createResolvingSyncTransport($resolver, true, 1.0);
        self::assertSame('pub-db', $transport->getDsn()->getPublicKey());

        $this->store->record = null;
        self::assertFalse($transport->send('body'));
        self::assertSame(['pub-db'], $ingest->publicKeys());
    }

    public function testRotatingTransportPassesOtherResultsThroughAndNeverThrows(): void
    {
        $resolved = $this->resolver(0)->resolve();
        $dsn      = $resolved->dsn();
        self::assertInstanceOf(BeaconDsn::class, $dsn);
        $transport = new RotatingEnvelopeTransport(
            $resolved,
            $dsn,
            static fn (BeaconDsn $target): EnvelopeTransport => new EnvelopeTransport(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])), $target),
            $this->resolver(0),
        );

        self::assertFalse($transport->send('body'));
        self::assertSame(500, $transport->sendDetailed('body')->getStatusCode());
        self::assertSame($dsn, $transport->getDsn());
        self::assertSame($dsn, $transport->currentTransport()->getDsn());

        $throwing = new RotatingEnvelopeTransport(
            $resolved,
            $dsn,
            static fn (BeaconDsn $target): EnvelopeTransport => new EnvelopeTransport(new MockHttpClient(new MockResponse('', ['http_code' => 401])), $target),
            new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', new InMemoryCredentialsStore(), 0, $this->clock),
        );
        $result = new TransportResult(false, 401);
        self::assertSame($result, $throwing->afterResult('body', $result), 'unarmed resolver: no refresh, the 401 stands');

        $failing = new RotatingEnvelopeTransport(
            $this->withPrevious(),
            $dsn,
            static function (BeaconDsn $target) use ($dsn): EnvelopeTransport {
                if ($target !== $dsn) {
                    throw new RuntimeException('cannot build');
                }

                return new EnvelopeTransport(new MockHttpClient(), $target);
            },
            $this->resolver(0),
        );
        self::assertSame($result, $failing->afterResult('body', $result), 'a failing fallback keeps the original rejection');
    }

    public function testMessengerHandlerTransportFollowsTheCredentials(): void
    {
        $ingest              = new ScriptedIngest(['fixture-secret-db', 'fixture-secret-next']);
        $this->store->record = new BeaconCredentials(F::DB_DSN);
        $resolver            = $this->resolver(0);
        $transport           = $this->factory($ingest)->createResolvingSyncTransport($resolver);

        self::assertTrue($transport->send('a'));
        self::assertTrue($transport->send('b'));
        $this->store->record = new BeaconCredentials(F::NEXT_DSN);
        self::assertTrue($transport->send('c'));
        self::assertSame(['pub-db', 'pub-db', 'pub-next'], $ingest->publicKeys());

        $none = $this->factory($ingest)->createResolvingSyncTransport(new BeaconCredentialsResolver(new BeaconDsnParser(), '', ''));
        self::assertFalse($none->send('dropped'));
        $this->expectException(LogicException::class);
        $none->getDsn();
    }

    public function testAsyncModeResendsA401AtFlushAndFlushesTheReplacedGeneration(): void
    {
        $ingest              = new ScriptedIngest(['fixture-secret-db', 'fixture-secret-next']);
        $this->store->record = new BeaconCredentials(F::NEXT_DSN, null, F::DB_DSN, new DateTimeImmutable('2026-11-12T11:00:00+00:00'));
        $registry            = new PendingTransportRegistry();
        $resolver            = $this->resolver(0);
        $client              = $this->factory($ingest, $registry)->createResolving($resolver, 'prod', null, 'h', true, 1.0, [], null, 'async');

        $ingest->acceptedSecrets = ['fixture-secret-db'];
        $client->captureMessage('queued');
        self::assertSame(['pub-next'], $ingest->publicKeys(), 'started, not finalized');
        $registry->flush();
        self::assertSame(['pub-next', 'pub-db'], $ingest->publicKeys(), 'the 401 read at flush is re-sent with the previous DSN');

        // A change between two captures completes the pending requests of the replaced generation first.
        $ingest->calls           = [];
        $ingest->acceptedSecrets = ['fixture-secret-db', 'fixture-secret-next'];
        $client->captureMessage('gen A');
        $this->store->record = new BeaconCredentials(F::DB_DSN);
        $client->captureMessage('gen B');
        $registry->flush();
        self::assertSame(['pub-next', 'pub-db'], $ingest->publicKeys());
    }

    public function testMessengerModeQueuesWithTheCurrentDsn(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        $this->store->record = new BeaconCredentials(F::DB_DSN);
        $ingest              = new ScriptedIngest([]);
        $factory             = new BeaconClientFactory(new BeaconDsnParser(), $ingest->client(), $this->logger, messageBus: $bus);
        $client              = $factory->createResolving($this->resolver(0), 'prod', null, 'h', true, 1.0, [], null, 'messenger');

        self::assertNotNull($client->captureMessage('queued'));
        self::assertCount(1, $bus->messages);
        self::assertInstanceOf(SendBeaconEnvelopeMessage::class, $bus->messages[0]);
        self::assertSame([], $ingest->calls);

        $registry = new PendingTransportRegistry();
        $fallback = (new BeaconClientFactory(new BeaconDsnParser(), $ingest->client(), $this->logger, pendingRegistry: $registry))
            ->createResolving($this->resolver(0), 'prod', null, 'h', true, 1.0, [], null, 'messenger');
        self::assertNotNull($fallback->captureMessage('no bus: async'));
        $registry->flush();
        self::assertSame(['pub-db'], $ingest->publicKeys());
    }

    public function testAsyncCallbackFailuresNeverEscape(): void
    {
        $dsn   = (new BeaconDsnParser())->parse(F::DB_DSN);
        $async = new AsyncEnvelopeTransport(
            new EnvelopeTransport(new MockHttpClient([new MockResponse('', ['http_code' => 401]), new MockResponse('', ['http_code' => 401])]), $dsn),
            1,
            static fn (string $body, TransportResult $result): never => throw new RuntimeException('callback'),
        );

        self::assertTrue($async->send('one'));
        self::assertTrue($async->send('two'), 'the oldest is finalized when the queue is full');
        $async->flush();
        $this->addToAssertionCount(1);
    }

    private function factory(ScriptedIngest $ingest, ?PendingTransportRegistry $registry = null): BeaconClientFactory
    {
        $http = $ingest->client();

        return new BeaconClientFactory(new BeaconDsnParser(), $http, $this->logger, pendingRegistry: $registry, syncHttpClient: $http);
    }

    private function resolver(int $ttl): BeaconCredentialsResolver
    {
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, F::CONFIG_KEY, $this->store, $ttl, $this->clock, $this->logger);
        $resolver->arm();

        return $resolver;
    }

    private function withPrevious(): ResolvedBeaconCredentials
    {
        $store    = new InMemoryCredentialsStore(new BeaconCredentials(F::NEXT_DSN, null, F::DB_DSN, new DateTimeImmutable('2026-11-12T11:00:00+00:00')));
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', $store, 0, $this->clock);
        $resolver->arm();

        return $resolver->resolve();
    }

    private function assertNoSecretLogged(): void
    {
        $logged = print_r($this->logger->records, true);
        foreach (F::secrets() as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }
}
