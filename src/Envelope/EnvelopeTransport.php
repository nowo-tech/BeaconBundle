<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Client\ClientUserAgent;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Synchronous HTTP transport that POSTs Envelope bodies to Beacon ingest.
 *
 * Authentication uses both:
 * - `X-Beacon-Auth` with `beacon_key` + `beacon_secret` (preferred by Symfony Beacon)
 * - the full DSN (including secret) embedded in the envelope header
 */
final class EnvelopeTransport implements EnvelopeTransportInterface
{
    private readonly string $clientName;

    private ?string $lastStartError = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BeaconDsn $dsn,
        private readonly bool $verifyPeer = true,
        private readonly float $timeout = 5.0,
        private readonly ?LoggerInterface $logger = null,
        ?string $clientName = null,
    ) {
        $this->clientName = $clientName ?? ClientUserAgent::resolve();
    }

    /**
     * Logger used for ingest failures (NullLogger when none was injected).
     */
    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $envelopeBody): bool
    {
        return $this->sendDetailed($envelopeBody)->isAccepted();
    }

    /**
     * POST an Envelope and return status / error details (for diagnostics).
     */
    public function sendDetailed(string $envelopeBody): TransportResult
    {
        $this->lastStartError = null;
        $response             = $this->startRequest($envelopeBody);
        if (!$response instanceof ResponseInterface) {
            return new TransportResult(
                false,
                null,
                $this->lastStartError ?? 'HTTP client could not start the ingest request.',
            );
        }

        return $this->finalizeResponseDetailed($response);
    }

    /**
     * Start the HTTP POST without reading the status (used by async transport).
     *
     * @internal
     */
    public function startRequest(string $envelopeBody): ?ResponseInterface
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
            return $this->httpClient->request('POST', $this->dsn->getEnvelopeUrl(), $options);
        } catch (TransportExceptionInterface $exception) {
            $this->lastStartError = $exception->getMessage();
            $this->logger()->error('Beacon ingest transport failed.', [
                'exception' => $exception->getMessage(),
                'url'       => $this->dsn->getEnvelopeUrl(),
            ]);

            return null;
        }
    }

    /**
     * Read status / log rejection for a previously started response.
     *
     * @internal
     */
    public function finalizeResponse(ResponseInterface $response): bool
    {
        return $this->finalizeResponseDetailed($response)->isAccepted();
    }

    /**
     * Read status / log rejection and return a structured result.
     *
     * @internal
     */
    public function finalizeResponseDetailed(ResponseInterface $response): TransportResult
    {
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $this->logger()->error('Beacon ingest transport failed.', [
                'exception' => $exception->getMessage(),
                'url'       => $this->dsn->getEnvelopeUrl(),
            ]);

            return new TransportResult(false, null, $exception->getMessage());
        }

        if ($status >= 200 && $status < 300) {
            return new TransportResult(true, $status);
        }

        $this->logRejectedResponse($status, $response);

        return new TransportResult(false, $status);
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
     * {@inheritdoc}
     */
    public function getDsn(): BeaconDsn
    {
        return $this->dsn;
    }
}
