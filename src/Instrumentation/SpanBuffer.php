<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use Nowo\Beacon\Span\SpanBuffer as CoreSpanBuffer;
use Symfony\Contracts\Service\ResetInterface;

use function sprintf;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Deprecated thin wrapper around beacon-php's {@see CoreSpanBuffer}, kept so code that type-hints this class keeps
 * working.
 *
 * Since 1.27.0 the bundle registers {@see CoreSpanBuffer} itself as the request-scoped service (tagged
 * `kernel.reset`). This class holds no state of its own: the service `Nowo\BeaconBundle\Instrumentation\SpanBuffer`
 * wraps that same core instance. `new SpanBuffer()` wraps a fresh core instance.
 *
 * @phpstan-import-type Span from CoreSpanBuffer
 *
 * @deprecated since nowo-tech/beacon-bundle 1.27.0, type-hint {@see CoreSpanBuffer} (service id
 *             `Nowo\Beacon\Span\SpanBuffer`) instead; this class is removed in 2.0
 */
final readonly class SpanBuffer implements ResetInterface
{
    public const MAX_ITEMS = CoreSpanBuffer::MAX_ITEMS;

    private CoreSpanBuffer $inner;

    public function __construct(?CoreSpanBuffer $inner = null)
    {
        @trigger_error(sprintf('Since nowo-tech/beacon-bundle 1.27.0: The "%s" class is deprecated, type-hint "%s" instead.', self::class, CoreSpanBuffer::class), E_USER_DEPRECATED);

        $this->inner = $inner ?? new CoreSpanBuffer();
    }

    /**
     * The beacon-php buffer this wrapper delegates to.
     */
    public function getInner(): CoreSpanBuffer
    {
        return $this->inner;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function add(
        string $op,
        string $description,
        float $startTimestamp,
        float $endTimestamp,
        array $data = [],
    ): void {
        $this->inner->add($op, $description, $startTimestamp, $endTimestamp, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addTimed(
        string $op,
        string $description,
        float $durationSeconds,
        array $data = [],
        ?float $endTimestamp = null,
    ): void {
        $this->inner->addTimed($op, $description, $durationSeconds, $data, $endTimestamp);
    }

    /**
     * @return list<Span>
     */
    public function all(): array
    {
        return $this->inner->all();
    }

    /**
     * @return list<Span>
     */
    public function drain(): array
    {
        return $this->inner->drain();
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
