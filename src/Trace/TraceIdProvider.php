<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Trace;

use Symfony\Contracts\Service\ResetInterface;

use function bin2hex;
use function is_string;
use function preg_match;
use function random_bytes;
use function trim;

/**
 * Request/worker-scoped correlation id attached to every Beacon event.
 */
final class TraceIdProvider implements ResetInterface
{
    public const HEADER = 'X-Beacon-Trace-Id';

    public const REQUEST_ATTRIBUTE = '_beacon_trace_id';

    private ?string $traceId = null;

    /**
     * Current trace id, generating one when missing.
     */
    public function getOrCreate(): string
    {
        if ($this->traceId === null || $this->traceId === '') {
            $this->traceId = self::generate();
        }

        return $this->traceId;
    }

    /**
     * Current trace id or null when none was set/generated yet.
     */
    public function get(): ?string
    {
        return $this->traceId;
    }

    /**
     * Force a trace id (e.g. from inbound header or Messenger stamp).
     */
    public function set(string $traceId): void
    {
        $normalized = self::normalize($traceId);
        if ($normalized !== null) {
            $this->traceId = $normalized;
        }
    }

    public function reset(): void
    {
        $this->traceId = null;
    }

    /**
     * @return non-empty-string
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function normalize(string $traceId): ?string
    {
        $traceId = trim($traceId);
        if ($traceId === '' || preg_match('/^[A-Za-z0-9._-]{8,128}$/', $traceId) !== 1) {
            return null;
        }

        return $traceId;
    }

    public static function fromMixed(mixed $value): ?string
    {
        return is_string($value) ? self::normalize($value) : null;
    }
}
