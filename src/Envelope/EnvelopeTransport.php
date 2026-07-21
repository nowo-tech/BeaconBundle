<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP transport that POSTs Envelope bodies to Beacon ingest.
 *
 * Authentication uses both:
 * - `X-Beacon-Auth` with `beacon_key` + `beacon_secret` (preferred by Symfony Beacon)
 * - the full DSN (including secret) embedded in the envelope header
 */
final class EnvelopeTransport
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BeaconDsn $dsn,
        private readonly bool $verifyPeer = true,
        private readonly float $timeout = 5.0,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $clientName = 'beacon-bundle/1.5',
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
                'Content-Type'  => 'application/x-beacon-envelope',
                'User-Agent'    => $this->clientName,
                'X-Beacon-Auth' => $this->dsn->getBeaconAuthHeader(),
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

            $this->logRejectedResponse($status, $response);

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
     * @param int $status HTTP status from Beacon ingest
     */
    private function logRejectedResponse(int $status, ResponseInterface $response): void
    {
        $context = [
            'status' => $status,
            'url'    => $this->dsn->getEnvelopeUrl(),
        ];

        if ($status === 429) {
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            if ($retryAfter !== null && $retryAfter !== '') {
                $context['retry_after'] = $retryAfter;
            }

            $this->logger()->warning('Beacon ingest rate limited (HTTP 429). Respect Retry-After before retrying.', $context);

            return;
        }

        if ($status === 401 || $status === 403) {
            $this->logger()->warning(
                'Beacon ingest authentication rejected. Confirm BEACON_DSN includes public:secret and matches the project.',
                $context,
            );

            return;
        }

        $this->logger()->warning('Beacon ingest rejected envelope.', $context);
    }

    /**
     * DSN used to build the ingest URL and envelope header.
     */
    public function getDsn(): BeaconDsn
    {
        return $this->dsn;
    }
}
