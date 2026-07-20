<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Throwable;

use function is_array;
use function is_string;

/**
 * Default Beacon client: builds Envelope NDJSON and POSTs via {@see EnvelopeTransport}.
 */
final class BeaconClient implements BeaconClientInterface
{
    public function __construct(
        private readonly EnvelopeTransport $transport,
        private readonly EnvelopeBuilder $envelopeBuilder,
        private readonly bool $enabled = true,
        private readonly ?BreadcrumbBuffer $breadcrumbBuffer = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $body = $this->envelopeBuilder->buildEventEnvelope(
            $this->transport->getDsn(),
            $throwable->getMessage(),
            'error',
            $throwable,
            $extra,
            $fingerprint,
        );

        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * {@inheritdoc}
     */
    public function captureMessage(
        string $message,
        string $level = 'error',
        array $extra = [],
        ?array $fingerprint = null,
    ): ?string {
        if (!$this->enabled) {
            return null;
        }

        $body = $this->envelopeBuilder->buildEventEnvelope(
            $this->transport->getDsn(),
            $message,
            $level,
            null,
            $extra,
            $fingerprint,
        );

        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * {@inheritdoc}
     */
    public function addBreadcrumb(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = [],
    ): void {
        $this->breadcrumbBuffer?->add($message, $category, $level, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function captureTransaction(
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): ?string {
        if (!$this->enabled) {
            return null;
        }

        $body = $this->envelopeBuilder->buildTransactionEnvelope(
            $this->transport->getDsn(),
            $transactionName,
            $startTimestamp,
            $endTimestamp,
            $spans,
            $extra,
        );

        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * Parsed DSN used by the underlying transport.
     */
    public function getDsn(): BeaconDsn
    {
        return $this->transport->getDsn();
    }

    /**
     * Reads `event_id` from the first envelope header line.
     */
    private function extractEventId(string $envelopeBody): ?string
    {
        $firstLine = strtok($envelopeBody, "\n");
        if ($firstLine === false) {
            return null;
        }

        $decoded = json_decode($firstLine, true);
        if (!is_array($decoded)) {
            return null;
        }

        $eventId = $decoded['event_id'] ?? null;

        return is_string($eventId) ? $eventId : null;
    }
}
