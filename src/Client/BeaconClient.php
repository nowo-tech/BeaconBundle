<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransportInterface;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Throwable;

use function count;
use function is_array;
use function is_callable;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Default Beacon client: builds Envelope NDJSON and POSTs via {@see EnvelopeTransportInterface}.
 */
final class BeaconClient implements BeaconClientInterface
{
    /**
     * @param callable(array<string, mixed>): (?array<string, mixed>)|null $beforeSend
     */
    public function __construct(
        private readonly EnvelopeTransportInterface $transport,
        private readonly EnvelopeBuilder $envelopeBuilder,
        private readonly bool $enabled = true,
        private readonly ?BreadcrumbBuffer $breadcrumbBuffer = null,
        private readonly ?Scope $scope = null,
        private readonly ?SpanBuffer $spanBuffer = null,
        private readonly mixed $beforeSend = null,
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

        $body = $this->applyBeforeSend($body);
        if ($body === null) {
            return null;
        }

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

        $body = $this->applyBeforeSend($body);
        if ($body === null) {
            return null;
        }

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

        $buffered = $this->spanBuffer?->drain() ?? [];
        $spans    = [...$buffered, ...$spans];

        $body = $this->envelopeBuilder->buildTransactionEnvelope(
            $this->transport->getDsn(),
            $transactionName,
            $startTimestamp,
            $endTimestamp,
            $spans,
            $extra,
        );

        $body = $this->applyBeforeSend($body);
        if ($body === null) {
            return null;
        }

        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * {@inheritdoc}
     */
    public function setTag(string $key, mixed $value): void
    {
        $this->scope?->setTag($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function setTags(array $tags): void
    {
        $this->scope?->setTags($tags);
    }

    /**
     * {@inheritdoc}
     */
    public function getTags(): array
    {
        return $this->scope?->getTags() ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function clearTags(): void
    {
        $this->scope?->clearTags();
    }

    /**
     * Parsed DSN used by the underlying transport.
     */
    public function getDsn(): BeaconDsn
    {
        return $this->transport->getDsn();
    }

    /**
     * Runs `before_send` on the event/transaction payload line. Returns null to drop the send.
     */
    private function applyBeforeSend(string $envelopeBody): ?string
    {
        if (!is_callable($this->beforeSend)) {
            return $envelopeBody;
        }

        $lines = explode("\n", rtrim($envelopeBody, "\n"));
        if (count($lines) < 3) {
            return $envelopeBody;
        }

        $payload = json_decode($lines[2], true);
        if (!is_array($payload)) {
            return $envelopeBody;
        }

        try {
            $result = ($this->beforeSend)($payload);
        } catch (Throwable) {
            // Fail soft: drop the event so a buggy hook cannot break the host app.
            return null;
        }

        if ($result === null) {
            return null;
        }

        if (!is_array($result)) {
            return null;
        }

        $lines[2] = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", $lines) . "\n";
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
