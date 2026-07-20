<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
    ) {
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
        );
        $transport = new EnvelopeTransport(
            $this->httpClient,
            $parsed,
            $verifyPeer,
            $timeout,
            $this->logger,
        );

        return new BeaconClient($transport, $builder, true, $this->breadcrumbBuffer);
    }
}
