<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
 */
final class AsyncEnvelopeTransport implements FlushableEnvelopeTransportInterface
{
    public const DEFAULT_MAX_PENDING = 100;

    /** @var list<array{0: ResponseInterface, 1: bool, 2: string|null}> response, versioned path, body kept for a legacy retry */
    private array $pending = [];

    public function __construct(
        private readonly EnvelopeTransport $inner,
        private readonly int $maxPending = self::DEFAULT_MAX_PENDING,
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

        while (count($this->pending) >= max(1, $this->maxPending)) {
            // @igor-ignore - Not shared worker service state.
            $oldest = array_shift($this->pending);
            if ($oldest !== null) {
                $this->inner->finalizeStarted($oldest[0], $oldest[1], $oldest[2]);
            }
        }

        // @igor-ignore - Not shared worker service state.
        $this->pending[] = $started;

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

        foreach ($pending as [$response, $versioned, $body]) {
            $this->inner->finalizeStarted($response, $versioned, $body);
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
