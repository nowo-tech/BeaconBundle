<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\Beacon\Transport\IngestResponseHandler;
use Nowo\Beacon\Transport\TransportResult as CoreTransportResult;
use Nowo\BeaconBundle\Client\ClientUserAgent;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Ingest\IngestPathResolver;
use Nowo\BeaconBundle\Ingest\IngestPaths;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Synchronous HTTP transport that POSTs Envelope bodies to Beacon ingest.
 *
 * Same request as beacon-php's `SymfonyHttpClientTransport`: one POST with `X-Beacon-Auth` (`beacon_key` +
 * `beacon_secret`, the only place the secret is sent since 1.21), the bundle's `User-Agent`, `timeout` /
 * `max_duration`, redirects never followed (a 3xx is a failed send), `verify_peer` / `verify_host` always explicit
 * ({@see ExplicitTlsHttpClient}). Responses are logged by beacon-php's {@see IngestResponseHandler} (same messages and
 * context keys as before; transport error texts have the secret removed) and never throw.
 *
 * {@see startRequest()} / {@see finalizeResponse()} split the same request for {@see AsyncEnvelopeTransport}.
 *
 * Ingest path (1.23): the versioned `/api/ingest/v1/{projectId}/envelope/` by default; with `ingest_paths: auto` a
 * 404 / 405 there re-sends the same body once to the legacy `/api/{projectId}/envelope/` and the
 * {@see IngestPathResolver} remembers the origin's answer. No other status is re-sent here (5xx retries stay with the
 * retrying HTTP client). Log records keep beacon-php's `url` context value (the legacy URL).
 */
final class EnvelopeTransport implements EnvelopeTransportInterface
{
    /**
     * Context key (value `true`) set on every log record this transport writes.
     *
     * {@see BeaconMonologHandler} skips flagged records: forwarding
     * "Beacon ingest transport failed." to Beacon would queue a new send to the same unreachable
     * receiver, fail again and log again — one endless chain per failure while the receiver is down.
     */
    public const LOG_CONTEXT_FLAG = IngestResponseHandler::LOG_CONTEXT_FLAG;

    private readonly string $clientName;

    private readonly HttpClientInterface $tlsHttpClient;

    private readonly IngestResponseHandler $responses;

    private readonly IngestPathResolver $ingestPaths;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BeaconDsn $dsn,
        private readonly bool $verifyPeer = true,
        private readonly float $timeout = 5.0,
        private readonly ?LoggerInterface $logger = null,
        ?string $clientName = null,
        ?IngestPathResolver $ingestPaths = null,
    ) {
        $this->clientName    = $clientName ?? ClientUserAgent::resolve();
        $this->tlsHttpClient = new ExplicitTlsHttpClient($this->httpClient, $this->verifyPeer);
        $this->responses     = new IngestResponseHandler($this->dsn, $this->logger);
        $this->ingestPaths   = $ingestPaths ?? new IngestPathResolver();
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
        $probing   = $this->ingestPaths->isProbing($this->dsn->getOrigin());
        $versioned = $this->ingestPaths->useVersioned($this->dsn->getOrigin());
        $response  = $this->request($envelopeBody, $versioned);
        if ($response instanceof CoreTransportResult) {
            return $this->fromCore($response);
        }

        return $this->finalizeStarted($response, $versioned, $probing ? $envelopeBody : null);
    }

    /**
     * Envelope URL the next send starts with (versioned or legacy, see {@see IngestPathResolver}).
     */
    public function getEnvelopeUrl(): string
    {
        return IngestPaths::envelopeUrl($this->dsn, $this->ingestPaths->useVersioned($this->dsn->getOrigin()));
    }

    /**
     * Start the HTTP POST without reading the status (used by async transport). It goes to the path the next send
     * starts with; prefer {@see startProbeAwareRequest()}, which keeps what a legacy retry needs.
     *
     * @internal
     */
    public function startRequest(string $envelopeBody): ?ResponseInterface
    {
        $response = $this->request($envelopeBody, $this->ingestPaths->useVersioned($this->dsn->getOrigin()));

        return $response instanceof ResponseInterface ? $response : null;
    }

    /**
     * Start the HTTP POST and return what {@see finalizeStarted()} needs: the response, whether it went to the
     * versioned path, and the body while the origin is still being probed (null otherwise, so nothing large is kept).
     *
     * @internal
     *
     * @return array{0: ResponseInterface, 1: bool, 2: string|null}|null null when the request could not start (logged)
     */
    public function startProbeAwareRequest(string $envelopeBody): ?array
    {
        $origin    = $this->dsn->getOrigin();
        $probing   = $this->ingestPaths->isProbing($origin);
        $versioned = $this->ingestPaths->useVersioned($origin);
        $response  = $this->request($envelopeBody, $versioned);

        return $response instanceof ResponseInterface ? [$response, $versioned, $probing ? $envelopeBody : null] : null;
    }

    /**
     * Read the status of a started request; when it went to the versioned path, the origin is being probed and the
     * route is missing (404 / 405), re-send `$bodyForFallback` once to the legacy path. Logs exactly one outcome.
     *
     * @internal
     */
    public function finalizeStarted(ResponseInterface $response, bool $versioned, ?string $bodyForFallback): TransportResult
    {
        if ($versioned && $bodyForFallback !== null) {
            $status = $this->statusOf($response);
            if ($status !== null && $this->ingestPaths->versionedAnswered($this->dsn->getOrigin(), $status)) {
                $response->cancel();
                $legacy = $this->request($bodyForFallback, false);
                if ($legacy instanceof CoreTransportResult) {
                    return $this->fromCore($legacy);
                }

                $legacyStatus = $this->statusOf($legacy);
                if ($legacyStatus !== null) {
                    $this->ingestPaths->legacyAnswered($this->dsn->getOrigin(), $legacyStatus);
                }

                return $this->finalizeResponseDetailed($legacy);
            }
        }

        return $this->finalizeResponseDetailed($response);
    }

    /**
     * Start one POST to the versioned or legacy envelope URL; a failure to start is logged and returned.
     */
    private function request(string $envelopeBody, bool $versioned): ResponseInterface|CoreTransportResult
    {
        // Same request as beacon-php's SymfonyHttpClientTransport::send().
        $options = [
            'headers' => [
                'Content-Type'  => 'application/x-beacon-envelope',
                'User-Agent'    => $this->clientName,
                'X-Beacon-Auth' => $this->dsn->getBeaconAuthHeader(),
            ],
            'body'         => $envelopeBody,
            'timeout'      => $this->timeout,
            'max_duration' => $this->timeout,
            // Never follow a redirect: it would carry the Beacon credentials to another host.
            'max_redirects' => 0,
        ];

        try {
            return $this->tlsHttpClient->request('POST', IngestPaths::envelopeUrl($this->dsn, $versioned), $options);
        } catch (Throwable $exception) {
            return $this->responses->fromFailure($exception);
        }
    }

    /**
     * HTTP status without logging, or null on a transport failure (no HTTP answer).
     */
    private function statusOf(ResponseInterface $response): ?int
    {
        try {
            return $response->getStatusCode();
        } catch (Throwable) {
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
        } catch (Throwable $exception) {
            return $this->fromCore($this->responses->fromFailure($exception));
        }

        return $this->fromCore($this->responses->fromStatus(
            $status,
            // @igor-ignore - Per-call header reader passed to the handler and dropped; nothing is stored on the service.
            static function (string $name) use ($response): ?string {
                try {
                    return $response->getHeaders(false)[$name][0] ?? null;
                } catch (Throwable) {
                    return null;
                }
            },
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getDsn(): BeaconDsn
    {
        return $this->dsn;
    }

    private function fromCore(CoreTransportResult $result): TransportResult
    {
        // As before, an error text only accompanies transport failures (no HTTP status); a 3xx keeps a null text.
        return new TransportResult($result->accepted, $result->statusCode, $result->statusCode === null ? $result->errorMessage : null);
    }
}
