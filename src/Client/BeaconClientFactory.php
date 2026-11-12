<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Closure;
use InvalidArgumentException;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Credentials\ResolvedBeaconCredentials;
use Nowo\BeaconBundle\Credentials\ResolvingEnvelopeTransport;
use Nowo\BeaconBundle\Credentials\RotatingEnvelopeTransport;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransportInterface;
use Nowo\BeaconBundle\Envelope\MessengerEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Nowo\BeaconBundle\Envelope\TransportResult;
use Nowo\BeaconBundle\Ingest\IngestPathResolver;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function interface_exists;

/**
 * Builds a live {@see BeaconClient} or {@see NullBeaconClient} after env vars are resolved.
 */
final class BeaconClientFactory
{
    private readonly IngestPathResolver $ingestPaths;

    public function __construct(
        private readonly BeaconDsnParser $parser,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?UserContextProviderInterface $userContextProvider = null,
        private readonly ?BreadcrumbBuffer $breadcrumbBuffer = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?Scope $scope = null,
        private readonly ?SpanBuffer $spanBuffer = null,
        private readonly ?PendingTransportRegistry $pendingRegistry = null,
        private readonly ?object $messageBus = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?TraceIdProvider $traceIdProvider = null,
        private readonly ?HttpClientInterface $syncHttpClient = null,
        ?IngestPathResolver $ingestPaths = null,
    ) {
        $this->ingestPaths = $ingestPaths ?? new IngestPathResolver();
    }

    /**
     * Create a synchronous HTTP Envelope transport (used by Messenger workers and as the base for wrappers).
     */
    public function createSyncTransport(
        bool $enabled,
        #[SensitiveParameter]
        ?string $dsn,
        bool $verifyPeer = true,
        float $timeout = 5.0,
    ): EnvelopeTransport {
        $parsed = $this->requireParsedDsn($enabled, $dsn);

        return new EnvelopeTransport(
            $this->httpClient,
            $parsed,
            $verifyPeer,
            $timeout,
            $this->logger,
            ingestPaths: $this->ingestPaths,
        );
    }

    /**
     * Create a live client or {@see NullBeaconClient} after the DSN env value is resolved.
     *
     * @param array{
     *     environment?: bool,
     *     release?: bool,
     *     server_name?: bool,
     *     stacktrace?: bool,
     *     request?: bool,
     *     user?: bool,
     *     runtime?: bool,
     *     framework?: bool,
     *     os?: bool
     * } $send Outbound context switches (`send.*`)
     * @param callable(array<string, mixed>): (?array<string, mixed>)|null $beforeSend
     * @param string $transportMode One of `sync`, `async`, `messenger`
     */
    public function create(
        bool $enabled,
        #[SensitiveParameter]
        ?string $dsn,
        string $environment,
        ?string $release,
        string $serverName,
        bool $verifyPeer,
        float $timeout,
        array $send = [],
        mixed $beforeSend = null,
        string $transportMode = 'sync',
    ): BeaconClientInterface {
        $dsn = trim((string) $dsn);
        if (!$enabled || $dsn === '') {
            return new NullBeaconClient();
        }

        $parsed  = $this->parser->parse($dsn);
        $builder = $this->envelopeBuilder($environment, $release, $serverName, $send);
        // `sync` blocks the caller, so it uses the client whose Retry-After wait is bounded (REQ-RUNTIME-001);
        // `async` and `messenger` send in the background and keep honouring the full Retry-After.
        $sync = new EnvelopeTransport(
            $transportMode === 'sync' ? ($this->syncHttpClient ?? $this->httpClient) : $this->httpClient,
            $parsed,
            $verifyPeer,
            $timeout,
            $this->logger,
            ingestPaths: $this->ingestPaths,
        );
        $transport = $this->wrapTransport($sync, $transportMode);

        return new BeaconClient(
            $transport,
            $builder,
            true,
            $this->breadcrumbBuffer,
            $this->scope,
            $this->spanBuffer,
            $beforeSend,
        );
    }

    /**
     * Client used when `nowo_beacon.credentials_provider` is set (1.25): the DSN is resolved at runtime on every capture
     * (cached for `credentials_cache_ttl`), the inner client, transport and async queue are rebuilt when the credentials
     * change, and an HTTP 401 falls back to the previous DSN or one refresh ({@see RotatingEnvelopeTransport}). Same
     * arguments as {@see create()} minus the DSN.
     *
     * @param array<string, bool> $send Outbound context switches (`send.*`)
     * @param callable(array<string, mixed>): (?array<string, mixed>)|null $beforeSend
     * @param string $transportMode One of `sync`, `async`, `messenger`
     */
    public function createResolving(
        BeaconCredentialsResolver $resolver,
        string $environment,
        ?string $release,
        string $serverName,
        bool $verifyPeer,
        float $timeout,
        array $send = [],
        mixed $beforeSend = null,
        string $transportMode = 'sync',
    ): ResolvingBeaconClient {
        $http  = $transportMode === 'sync' ? ($this->syncHttpClient ?? $this->httpClient) : $this->httpClient;
        $build = function (ResolvedBeaconCredentials $credentials) use ($resolver, $environment, $release, $serverName, $verifyPeer, $timeout, $send, $beforeSend, $transportMode, $http): BeaconClientInterface {
            // Requests started with the replaced credentials complete before the new async transport registers.
            $this->pendingRegistry?->flush();

            $dsn = $credentials->dsn();
            if (!$dsn instanceof BeaconDsn) {
                return new NullBeaconClient();
            }

            $rotating  = $this->rotating($resolver, $credentials, $dsn, $http, $verifyPeer, $timeout);
            $transport = $transportMode === 'sync'
                ? $rotating
                : $this->wrapTransport($rotating->currentTransport(), $transportMode, $rotating->afterResult(...));

            return new BeaconClient(
                $transport,
                $this->envelopeBuilder($environment, $release, $serverName, $send),
                true,
                $this->breadcrumbBuffer,
                $this->scope,
                $this->spanBuffer,
                $beforeSend,
            );
        };

        return new ResolvingBeaconClient($resolver, $build, $this->breadcrumbBuffer, $this->scope);
    }

    /**
     * Synchronous transport of the Messenger handler when `nowo_beacon.credentials_provider` is set (1.25): it POSTs with
     * the credentials valid when the message is handled.
     */
    public function createResolvingSyncTransport(
        BeaconCredentialsResolver $resolver,
        bool $verifyPeer = true,
        float $timeout = 5.0,
    ): ResolvingEnvelopeTransport {
        return new ResolvingEnvelopeTransport(
            $resolver,
            fn (ResolvedBeaconCredentials $credentials, BeaconDsn $dsn): RotatingEnvelopeTransport => $this->rotating($resolver, $credentials, $dsn, $this->httpClient, $verifyPeer, $timeout),
        );
    }

    private function rotating(
        BeaconCredentialsResolver $resolver,
        ResolvedBeaconCredentials $credentials,
        BeaconDsn $dsn,
        HttpClientInterface $http,
        bool $verifyPeer,
        float $timeout,
    ): RotatingEnvelopeTransport {
        return new RotatingEnvelopeTransport(
            $credentials,
            $dsn,
            fn (BeaconDsn $target): EnvelopeTransport => new EnvelopeTransport($http, $target, $verifyPeer, $timeout, $this->logger, ingestPaths: $this->ingestPaths),
            $resolver,
            $this->logger,
        );
    }

    /**
     * @param array<string, bool> $send
     */
    private function envelopeBuilder(string $environment, ?string $release, string $serverName, array $send): EnvelopeBuilder
    {
        return new EnvelopeBuilder(
            $environment,
            $release,
            $serverName,
            SendOptions::fromArray($send),
            $this->userContextProvider,
            $this->breadcrumbBuffer,
            $this->requestStack,
            5,
            $this->scope,
            $this->traceIdProvider,
            $this->clock,
        );
    }

    /**
     * @param string $transportMode One of `sync`, `async`, `messenger`
     * @param (Closure(string, TransportResult): mixed)|null $onUnauthorized
     */
    private function wrapTransport(EnvelopeTransport $sync, string $transportMode, ?Closure $onUnauthorized = null): EnvelopeTransportInterface
    {
        return match ($transportMode) {
            'async'     => $this->wrapAsync($sync, $onUnauthorized),
            'messenger' => $this->wrapMessenger($sync, $onUnauthorized),
            default     => $sync,
        };
    }

    /**
     * @param (Closure(string, TransportResult): mixed)|null $onUnauthorized
     */
    private function wrapAsync(EnvelopeTransport $sync, ?Closure $onUnauthorized = null): AsyncEnvelopeTransport
    {
        $async = new AsyncEnvelopeTransport($sync, onUnauthorized: $onUnauthorized);
        $this->pendingRegistry?->register($async);

        return $async;
    }

    /**
     * @param (Closure(string, TransportResult): mixed)|null $onUnauthorized
     */
    private function wrapMessenger(EnvelopeTransport $sync, ?Closure $onUnauthorized = null): EnvelopeTransportInterface
    {
        $busInterface = 'Symfony\\Component\\Messenger\\MessageBusInterface';
        if (
            $this->messageBus === null
            || !interface_exists($busInterface)
            || !($this->messageBus instanceof $busInterface)
        ) {
            $this->logger?->warning(
                'nowo_beacon.transport.mode=messenger requires symfony/messenger and a MessageBusInterface; falling back to async.',
                [EnvelopeTransport::LOG_CONTEXT_FLAG => true],
            );

            return $this->wrapAsync($sync, $onUnauthorized);
        }

        return new MessengerEnvelopeTransport($this->messageBus, $sync->getDsn());
    }

    private function requireParsedDsn(bool $enabled, #[SensitiveParameter] ?string $dsn): BeaconDsn
    {
        $dsn = trim((string) $dsn);
        if (!$enabled || $dsn === '') {
            throw new InvalidArgumentException('Beacon sync transport requires an enabled non-empty DSN.');
        }

        return $this->parser->parse($dsn);
    }
}
