<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Nowo\Beacon\Envelope\Envelope;
use Nowo\Beacon\Scrubber\PayloadScrubber;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransportInterface;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Throwable;

use function is_array;
use function is_callable;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Default Beacon client: builds Envelope NDJSON and POSTs via {@see EnvelopeTransportInterface}.
 *
 * Pipeline for events and transactions: {@see EnvelopeBuilder} (beacon-php core + the bundle's message / exception /
 * URL redaction) → beacon-php's {@see PayloadScrubber} (sensitive keys in `extra`, `contexts`, `tags` and breadcrumb
 * `data`, credential headers, cookies, credential patterns and Luhn-valid card numbers in strings) → `before_send` →
 * encode → transport. The hook never sees what the scrubber removed. Deploys, check-ins, schedule catalogs and queue
 * snapshots carry metadata only and skip both.
 */
final class BeaconClient implements BeaconClientInterface, QueueSnapshotCapableInterface, DeployCapableInterface, CheckInCapableInterface
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

        $body = $this->finalize($this->envelopeBuilder->eventEnvelope(
            $this->transport->getDsn(),
            $throwable->getMessage(),
            'error',
            $throwable,
            $extra,
            $fingerprint,
        ));
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

        $body = $this->finalize($this->envelopeBuilder->eventEnvelope(
            $this->transport->getDsn(),
            $message,
            $level,
            null,
            $extra,
            $fingerprint,
        ));
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

        $body = $this->finalize($this->envelopeBuilder->transactionEnvelope(
            $this->transport->getDsn(),
            $transactionName,
            $startTimestamp,
            $endTimestamp,
            $spans,
            $extra,
        ));
        if ($body === null) {
            return null;
        }

        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * {@inheritdoc}
     *
     * Deploy markers carry only release metadata, so `before_send` is not applied.
     */
    public function captureDeploy(array $deploy): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $body = $this->envelopeBuilder->buildDeployEnvelope($this->transport->getDsn(), $deploy);
        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * {@inheritdoc}
     *
     * Snapshots carry only transport names and counts, so `before_send` is not applied.
     */
    public function captureQueueSnapshot(array $transports): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $body = $this->envelopeBuilder->buildQueueSnapshotEnvelope($this->transport->getDsn(), $transports);
        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * {@inheritdoc}
     *
     * Catalogs carry only schedule metadata, so `before_send` is not applied.
     */
    public function captureScheduleCatalog(array $schedules): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $body = $this->envelopeBuilder->buildScheduleCatalogEnvelope($this->transport->getDsn(), $schedules);
        $this->transport->send($body);

        return $this->extractEventId($body);
    }

    /**
     * {@inheritdoc}
     *
     * Check-ins carry only run metadata, so `before_send` is not applied.
     */
    public function captureCheckIn(array $checkIn): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $body = $this->envelopeBuilder->buildCheckInEnvelope($this->transport->getDsn(), $checkIn);
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
     * Scrubs the event/transaction payload, runs `before_send` on it and encodes the envelope. Returns null to drop
     * the send.
     */
    private function finalize(Envelope $envelope): ?string
    {
        $payload = (new PayloadScrubber())->scrub($envelope->payload);
        if (!is_callable($this->beforeSend)) {
            return EnvelopeBuilder::encode($envelope->withPayload($this->stripUserIp($payload)));
        }

        // The hook receives the payload as decoded from its JSON line (as before).
        $decoded = json_decode(EnvelopeBuilder::encodeLine($payload), true);

        try {
            $result = ($this->beforeSend)($decoded);
        } catch (Throwable) {
            // Fail soft: drop the event so a buggy hook cannot break the host app.
            return null;
        }

        if (!is_array($result)) {
            return null;
        }

        return EnvelopeBuilder::encodeLine($envelope->header) . "\n"
            . EnvelopeBuilder::encodeLine($envelope->itemHeader) . "\n"
            . json_encode($this->stripUserIp($result), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Without `send.client`, `user.ip_address` is removed after `before_send` (as beacon-php's `IpStripper` does for
     * the user), whoever set it: `Scope::setUser()`, a user provider or the hook.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function stripUserIp(array $payload): array
    {
        if (!$this->envelopeBuilder->sendsClientAddress() && isset($payload['user']) && is_array($payload['user'])) {
            unset($payload['user']['ip_address']);
        }

        return $payload;
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
