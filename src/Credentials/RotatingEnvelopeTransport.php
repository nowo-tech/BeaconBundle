<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Credentials;

use Closure;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransportInterface;
use Nowo\BeaconBundle\Envelope\TransportResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Synchronous Envelope transport of one credentials generation, with the rotation fallbacks.
 *
 * Sends with the current DSN. When Beacon answers HTTP 401:
 * 1. a previous DSN that has not expired (same origin and project): the same body is re-sent once with it (once it
 *    was rejected too, this step is skipped until the credentials change);
 * 2. otherwise the credentials are re-read once ({@see BeaconCredentialsResolver::refresh()}, with backoff) and, when
 *    the DSN changed, the body is re-sent once with the new one;
 * 3. otherwise the 401 stands (logged by the transport as before).
 *
 * The Envelope header carries no DSN (`EnvelopeDsnMode::None`), so the same body is valid for every DSN of the
 * project. Log records name the step, never a key or secret.
 *
 * @internal built by {@see BeaconClientFactory} when a credentials provider is configured
 */
final class RotatingEnvelopeTransport implements EnvelopeTransportInterface
{
    public const UNAUTHORIZED = 401;

    private readonly EnvelopeTransport $current;

    /** The previous DSN was rejected too: skip it for the rest of this credentials version (audit NS-I3). */
    private bool $previousRejected = false;

    /**
     * @param Closure(BeaconDsn): EnvelopeTransport $transportFor builds a synchronous transport for one DSN
     */
    public function __construct(
        private readonly ResolvedBeaconCredentials $credentials,
        BeaconDsn $dsn,
        private readonly Closure $transportFor,
        private readonly BeaconCredentialsResolver $resolver,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->current = ($this->transportFor)($dsn);
    }

    /**
     * Transport of the current DSN (the base of the async wrapper).
     */
    public function currentTransport(): EnvelopeTransport
    {
        return $this->current;
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $envelopeBody): bool
    {
        return $this->sendDetailed($envelopeBody)->isAccepted();
    }

    /**
     * POST with the current DSN, then apply the 401 fallbacks.
     */
    public function sendDetailed(string $envelopeBody): TransportResult
    {
        return $this->afterResult($envelopeBody, $this->current->sendDetailed($envelopeBody));
    }

    /**
     * Apply the 401 fallbacks to the result of a send made with the current DSN (also called by the async wrapper at
     * flush). Never throws.
     */
    public function afterResult(string $envelopeBody, TransportResult $result): TransportResult
    {
        if ($result->getStatusCode() !== self::UNAUTHORIZED) {
            return $result;
        }

        try {
            $previous = $this->previousRejected ? null : $this->credentials->previousDsn($this->resolver->now());
            if ($previous instanceof BeaconDsn) {
                $this->log('Beacon ingest answered HTTP 401 to the current DSN; the Envelope is re-sent once with the previous DSN.');
                $retried = ($this->transportFor)($previous)->sendDetailed($envelopeBody);
                if ($retried->getStatusCode() === self::UNAUTHORIZED) {
                    // @igor-ignore - Per credentials version (this object is rebuilt when they change), no request data.
                    $this->previousRejected = true;
                }

                return $retried;
            }

            if (!$this->resolver->refresh()) {
                return $result;
            }

            $fresh = $this->resolver->resolve()->dsn();
            if (!$fresh instanceof BeaconDsn) {
                return $result;
            }

            $this->log('Beacon ingest answered HTTP 401; the credentials were re-read and the Envelope is re-sent once with the new DSN.');

            return ($this->transportFor)($fresh)->sendDetailed($envelopeBody);
        } catch (Throwable) {
            // Reporting never throws: keep the original rejection.
            return $result;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDsn(): BeaconDsn
    {
        return $this->current->getDsn();
    }

    private function log(string $message): void
    {
        $this->logger?->warning($message, [
            'project_id'                        => $this->current->getDsn()->getProjectId(),
            EnvelopeTransport::LOG_CONTEXT_FLAG => true,
        ]);
    }
}
