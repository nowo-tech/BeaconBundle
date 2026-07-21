<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Non-blocking transport: starts the HTTP POST immediately but defers status handling until {@see flush()}.
 *
 * Pair with {@see \Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener} on kernel/console terminate.
 */
final class AsyncEnvelopeTransport implements FlushableEnvelopeTransportInterface
{
    /** @var list<ResponseInterface> */
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
        $response = $this->inner->startRequest($envelopeBody);
        if (!$response instanceof ResponseInterface) {
            return false;
        }

        $this->pending[] = $response;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): void
    {
        $pending       = $this->pending;
        $this->pending = [];

        foreach ($pending as $response) {
            $this->inner->finalizeResponse($response);
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
