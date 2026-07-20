<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP transport that POSTs Envelope bodies to Beacon ingest.
 *
 * Authentication uses the DSN embedded in the envelope header (public key + project).
 */
final class EnvelopeTransport
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BeaconDsn $dsn,
        private readonly bool $verifyPeer = true,
        private readonly float $timeout = 5.0,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $clientName = 'beacon-bundle/1.0',
    ) {
    }

    /**
     * Logger used for ingest failures (NullLogger when none was injected).
     */
    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    /**
     * POST an Envelope body to Beacon. Returns true on HTTP 2xx.
     */
    public function send(string $envelopeBody): bool
    {
        $options = [
            'headers' => [
                'Content-Type' => 'application/x-beacon-envelope',
                'User-Agent'   => $this->clientName,
            ],
            'body'         => $envelopeBody,
            'timeout'      => $this->timeout,
            'max_duration' => $this->timeout,
        ];

        if (!$this->verifyPeer) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        try {
            $response = $this->httpClient->request('POST', $this->dsn->getEnvelopeUrl(), $options);
            $status   = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logger()->warning('Beacon ingest rejected envelope.', [
                'status' => $status,
                'url'    => $this->dsn->getEnvelopeUrl(),
            ]);

            return false;
        } catch (TransportExceptionInterface $exception) {
            $this->logger()->error('Beacon ingest transport failed.', [
                'exception' => $exception->getMessage(),
                'url'       => $this->dsn->getEnvelopeUrl(),
            ]);

            return false;
        }
    }

    /**
     * DSN used to build the ingest URL and envelope header.
     */
    public function getDsn(): BeaconDsn
    {
        return $this->dsn;
    }
}
