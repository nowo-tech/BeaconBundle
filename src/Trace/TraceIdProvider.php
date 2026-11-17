<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Trace;

use Nowo\Beacon\Trace\TraceIdProvider as CoreTraceIdProvider;
use Nowo\BeaconBundle\EventListener\BeaconTraceRequestListener;
use Symfony\Contracts\Service\ResetInterface;

use function trigger_deprecation;

/**
 * Deprecated thin wrapper around beacon-php's {@see CoreTraceIdProvider}, kept so code that type-hints this class (or
 * reads {@see REQUEST_ATTRIBUTE}) keeps working.
 *
 * Since 1.27.0 the bundle registers {@see CoreTraceIdProvider} itself as the request-scoped service (tagged
 * `kernel.reset`). This class holds no state of its own: the service `Nowo\BeaconBundle\Trace\TraceIdProvider` wraps
 * that same core instance. `new TraceIdProvider()` wraps a fresh core instance.
 *
 * @deprecated since nowo-tech/beacon-bundle 1.27.0, type-hint {@see CoreTraceIdProvider} (service id
 *             `Nowo\Beacon\Trace\TraceIdProvider`) and read {@see BeaconTraceRequestListener::REQUEST_ATTRIBUTE}
 *             instead; this class is removed in 2.0
 */
final readonly class TraceIdProvider implements ResetInterface
{
    public const HEADER = CoreTraceIdProvider::HEADER;

    /**
     * @deprecated since nowo-tech/beacon-bundle 1.27.0, use {@see BeaconTraceRequestListener::REQUEST_ATTRIBUTE}
     */
    public const REQUEST_ATTRIBUTE = BeaconTraceRequestListener::REQUEST_ATTRIBUTE;

    private CoreTraceIdProvider $inner;

    public function __construct(?CoreTraceIdProvider $inner = null)
    {
        trigger_deprecation('nowo-tech/beacon-bundle', '1.27.0', 'The "%s" class is deprecated, type-hint "%s" instead.', self::class, CoreTraceIdProvider::class);

        $this->inner = $inner ?? new CoreTraceIdProvider();
    }

    /**
     * The beacon-php provider this wrapper delegates to.
     */
    public function getInner(): CoreTraceIdProvider
    {
        return $this->inner;
    }

    public function getOrCreate(): string
    {
        return $this->inner->getOrCreate();
    }

    public function get(): ?string
    {
        return $this->inner->get();
    }

    public function set(string $traceId): void
    {
        $this->inner->set($traceId);
    }

    public function reset(): void
    {
        $this->inner->reset();
    }

    public static function generate(): string
    {
        return CoreTraceIdProvider::generate();
    }

    public static function normalize(string $traceId): ?string
    {
        return CoreTraceIdProvider::normalize($traceId);
    }

    public static function fromMixed(mixed $value): ?string
    {
        return CoreTraceIdProvider::fromMixed($value);
    }
}
