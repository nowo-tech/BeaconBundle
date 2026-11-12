<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Closure;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function array_shift;
use function count;
use function max;

/**
 * Non-blocking transport: starts the HTTP POST immediately but defers status handling until {@see flush()}.
 *
 * Pair with {@see FlushPendingTransportsListener} on kernel/console terminate.
 *
 * With `ingest_paths: auto`, each pending request to the versioned path keeps a reference to its body (the same string
 * the running request holds) so that a 404 / 405 can be re-sent once, synchronously at flush, to the legacy path.
 * At most {@see DEFAULT_MAX_PENDING} requests are pending, as in beacon-php: starting one more completes the oldest
 * first (audit LO-L3), so a long console command never accumulates responses or bodies without bound.
 *
 * With `$onUnauthorized` (1.25, credentials provider configured), every pending request also keeps its body, and an
 * HTTP 401 read at flush hands the body to that callback (the rotation fallback re-sends it synchronously).
 */
final class AsyncEnvelopeTransport implements FlushableEnvelopeTransportInterface
{
    public const DEFAULT_MAX_PENDING = 100;

    /** @var list<array{0: ResponseInterface, 1: bool, 2: string|null, 3: string|null}> response, versioned path, body kept for a legacy retry, body kept for the 401 callback */
    private array $pending = [];

    /**
     * @param (Closure(string, TransportResult): mixed)|null $onUnauthorized called with the body and result of a send that
     *                                                                       Beacon answered with HTTP 401 (never lets an exception out)
     */
    public function __construct(
        private readonly EnvelopeTransport $inner,
        private readonly int $maxPending = self::DEFAULT_MAX_PENDING,
        private readonly ?Closure $onUnauthorized = null,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Returns true when the request was started (optimistic). Call {@see flush()} to apply status logging.
     */
    public function send(string $envelopeBody): bool
    {
        $started = $this->inner->startProbeAwareRequest($envelopeBody);
        if ($started === null) {
            return false;
        }

        $entry = [$started[0], $started[1], $started[2], $this->onUnauthorized instanceof Closure ? $envelopeBody : null];

        while (count($this->pending) >= max(1, $this->maxPending)) {
            // @igor-ignore - Not shared worker service state.
            $oldest = array_shift($this->pending);
            if ($oldest !== null) {
                $this->finalize($oldest);
            }
        }

        // @igor-ignore - Not shared worker service state.
        $this->pending[] = $entry;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): void
    {
        $pending = $this->pending;
        // @igor-ignore - Not shared worker service state.
        $this->pending = [];

        foreach ($pending as $entry) {
            $this->finalize($entry);
        }
    }

    /**
     * @param array{0: ResponseInterface, 1: bool, 2: string|null, 3: string|null} $entry
     */
    private function finalize(array $entry): void
    {
        $result = $this->inner->finalizeStarted($entry[0], $entry[1], $entry[2]);
        if ($entry[3] === null || !$this->onUnauthorized instanceof Closure || $result->getStatusCode() !== 401) {
            return;
        }

        try {
            ($this->onUnauthorized)($entry[3], $result);
        } catch (Throwable) {
            // Reporting never throws.
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDsn(): BeaconDsn
    {
        return $this->inner->getDsn();
    }
}
