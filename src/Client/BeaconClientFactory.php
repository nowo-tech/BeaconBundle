<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Psr\Log\LoggerInterface;
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
    ) {
    }

    public function create(
        bool $enabled,
        ?string $dsn,
        string $environment,
        ?string $release,
        string $serverName,
        bool $verifyPeer,
        float $timeout,
    ): BeaconClientInterface {
        $dsn = trim((string) $dsn);
        if (!$enabled || $dsn === '') {
            return new NullBeaconClient();
        }

        $parsed    = $this->parser->parse($dsn);
        $builder   = new EnvelopeBuilder($environment, $release, $serverName);
        $transport = new EnvelopeTransport(
            $this->httpClient,
            $parsed,
            $verifyPeer,
            $timeout,
            $this->logger,
        );

        return new BeaconClient($transport, $builder, true);
    }
}
