<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\Beacon\Transport\IngestResponseHandler;
use Nowo\Beacon\Transport\SymfonyHttpClientTransport;
use Nowo\Beacon\Transport\TransportResult as CoreTransportResult;
use Nowo\BeaconBundle\Client\ClientUserAgent;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Synchronous HTTP transport that POSTs Envelope bodies to Beacon ingest.
 *
 * Wraps beacon-php's {@see SymfonyHttpClientTransport}: one POST with `X-Beacon-Auth` (`beacon_key` +
 * `beacon_secret`, the only place the secret is sent since 1.21), the bundle's `User-Agent`, `timeout` /
 * `max_duration`, redirects never followed (a 3xx is a failed send), `verify_peer` / `verify_host` always explicit
 * ({@see ExplicitTlsHttpClient}). Responses are logged by beacon-php's {@see IngestResponseHandler} (same messages and
 * context keys as before; transport error texts have the secret removed) and never throw.
 *
 * {@see startRequest()} / {@see finalizeResponse()} split the same request for {@see AsyncEnvelopeTransport}.
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

    private readonly SymfonyHttpClientTransport $core;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BeaconDsn $dsn,
        private readonly bool $verifyPeer = true,
        private readonly float $timeout = 5.0,
        private readonly ?LoggerInterface $logger = null,
        ?string $clientName = null,
    ) {
        $this->clientName    = $clientName ?? ClientUserAgent::resolve();
        $this->tlsHttpClient = new ExplicitTlsHttpClient($this->httpClient, $this->verifyPeer);
        $this->responses     = new IngestResponseHandler($this->dsn, $this->logger);
        $this->core          = new SymfonyHttpClientTransport(
            $this->tlsHttpClient,
            $this->dsn,
            $this->verifyPeer,
            $this->timeout,
            $this->logger,
            $this->clientName,
            maxRedirects: 0,
        );
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
        return $this->fromCore($this->core->send($envelopeBody));
    }

    /**
     * Start the HTTP POST without reading the status (used by async transport).
     *
     * @internal
     */
    public function startRequest(string $envelopeBody): ?ResponseInterface
    {
        // Same request as SymfonyHttpClientTransport::send().
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
            return $this->tlsHttpClient->request('POST', $this->dsn->getEnvelopeUrl(), $options);
        } catch (Throwable $exception) {
            $this->responses->fromFailure($exception);

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
