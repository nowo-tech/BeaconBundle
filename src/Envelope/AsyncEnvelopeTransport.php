<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Non-blocking transport: starts the HTTP POST immediately but defers status handling until {@see flush()}.
 *
 * Pair with {@see FlushPendingTransportsListener} on kernel/console terminate.
 *
 * While the Beacon origin is still being probed for the versioned ingest paths (`ingest_paths: auto`), each pending
 * request keeps its body so that a 404 / 405 can be re-sent once, synchronously at flush, to the legacy path. Once the
 * origin is known, no body is kept.
 */
final class AsyncEnvelopeTransport implements FlushableEnvelopeTransportInterface
{
    /** @var list<array{0: ResponseInterface, 1: bool, 2: string|null}> response, versioned path, body kept for a legacy retry */
    private array $pending = [];

    public function __construct(
        private readonly EnvelopeTransport $inner,
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
