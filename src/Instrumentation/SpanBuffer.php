<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use Symfony\Contracts\Service\ResetInterface;

use function array_slice;
use function bin2hex;
use function count;
use function microtime;
use function random_bytes;

/**
 * Request-scoped performance spans attached to the next transaction (and dual-written as breadcrumbs).
 *
 * @phpstan-type Span array{
 *     op: string,
 *     description: string,
 *     span_id: string,
 *     start_timestamp: float,
 *     timestamp: float,
 *     data?: array<string, mixed>
 * }
 */
final class SpanBuffer implements ResetInterface
{
    private const MAX_ITEMS = 100;

    /** @var list<Span> */
    private array $spans = [];

    /**
     * Append a span (oldest entries drop when the buffer exceeds the max size).
     *
     * @param array<string, mixed> $data
     */
    public function add(
        string $op,
        string $description,
        float $startTimestamp,
        float $endTimestamp,
        array $data = [],
    ): void {
        $span = [
            'op'              => $op,
            'description'     => $description,
            'span_id'         => bin2hex(random_bytes(8)),
            'start_timestamp' => $startTimestamp,
            'timestamp'       => $endTimestamp,
        ];
        if ($data !== []) {
            $span['data'] = $data;
        }

        $this->spans[] = $span;

        if (count($this->spans) > self::MAX_ITEMS) {
            $this->spans = array_slice($this->spans, -self::MAX_ITEMS);
        }
    }

    /**
     * Current spans in insertion order.
     *
     * @return list<Span>
     */
    public function all(): array
    {
        return $this->spans;
    }

    /**
     * Return all spans and clear the buffer.
     *
     * @return list<Span>
     */
    public function drain(): array
    {
        $spans       = $this->spans;
        $this->spans = [];

        return $spans;
    }

    /**
     * Remove all buffered spans.
     */
    public function clear(): void
    {
        $this->spans = [];
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->clear();
    }

    /**
     * Convenience helper used by instrumentation (starts at “now” if omitted).
     *
     * @param array<string, mixed> $data
     */
    public function addTimed(
        string $op,
        string $description,
        float $durationSeconds,
        array $data = [],
        ?float $endTimestamp = null,
    ): void {
        $end   = $endTimestamp ?? microtime(true);
        $start = $end - $durationSeconds;
        $this->add($op, $description, $start, $end, $data);
    }
}
