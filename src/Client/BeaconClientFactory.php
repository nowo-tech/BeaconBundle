<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use InvalidArgumentException;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransportInterface;
use Nowo\BeaconBundle\Envelope\MessengerEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function interface_exists;

/**
 * Builds a live {@see BeaconClient} or {@see NullBeaconClient} after env vars are resolved.
 */
final class BeaconClientFactory
{
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
    ) {
    }

    /**
     * Create a synchronous HTTP Envelope transport (used by Messenger workers and as the base for wrappers).
     */
    public function createSyncTransport(
        bool $enabled,
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

        $parsed      = $this->parser->parse($dsn);
        $sendOptions = SendOptions::fromArray($send);
        $builder     = new EnvelopeBuilder(
            $environment,
            $release,
            $serverName,
            $sendOptions,
            $this->userContextProvider,
            $this->breadcrumbBuffer,
            $this->requestStack,
            5,
            $this->scope,
        );
        $sync = new EnvelopeTransport(
            $this->httpClient,
            $parsed,
            $verifyPeer,
            $timeout,
            $this->logger,
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
     * @param string $transportMode One of `sync`, `async`, `messenger`
     */
    private function wrapTransport(EnvelopeTransport $sync, string $transportMode): EnvelopeTransportInterface
    {
        return match ($transportMode) {
            'async'     => $this->wrapAsync($sync),
            'messenger' => $this->wrapMessenger($sync),
            default     => $sync,
        };
    }

    private function wrapAsync(EnvelopeTransport $sync): AsyncEnvelopeTransport
    {
        $async = new AsyncEnvelopeTransport($sync);
        $this->pendingRegistry?->register($async);

        return $async;
    }

    private function wrapMessenger(EnvelopeTransport $sync): EnvelopeTransportInterface
    {
        $busInterface = 'Symfony\\Component\\Messenger\\MessageBusInterface';
        if (
            $this->messageBus === null
            || !interface_exists($busInterface)
            || !($this->messageBus instanceof $busInterface)
        ) {
            $this->logger?->warning(
                'nowo_beacon.transport.mode=messenger requires symfony/messenger and a MessageBusInterface; falling back to async.',
            );

            return $this->wrapAsync($sync);
        }

        return new MessengerEnvelopeTransport($this->messageBus, $sync->getDsn());
    }

    private function requireParsedDsn(bool $enabled, ?string $dsn): BeaconDsn
    {
        $dsn = trim((string) $dsn);
        if (!$enabled || $dsn === '') {
            throw new InvalidArgumentException('Beacon sync transport requires an enabled non-empty DSN.');
        }

        return $this->parser->parse($dsn);
    }
}
