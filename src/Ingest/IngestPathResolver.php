<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Ingest;

use Symfony\Contracts\Service\ResetInterface;

use function array_key_first;
use function count;

/**
 * Remembers, per Beacon origin, whether that Beacon serves the versioned ingest paths (`nowo_beacon.ingest_paths:
 * auto`).
 *
 * In `auto` mode an unknown origin is tried on the versioned path. A 404 / 405 there means an older Beacon: the same
 * request goes once more to the legacy path, and the answer is remembered:
 *
 * - versioned path answered anything else (2xx, 400, 401, 403, 413, 429, 5xx…) → versioned;
 * - legacy path answered something other than 404 / 405 → legacy;
 * - both answered 404 / 405 (unknown project, not a path problem) → versioned, so the origin is probed only once;
 * - a network failure (no HTTP answer) → nothing is learned and nothing is re-sent.
 *
 * Worker-safe (FrankenPHP worker mode without kernel reset): the map holds only a server capability (origin → bool),
 * never request, user or tenant data, and is bounded to {@see MAX_ORIGINS} entries (oldest dropped). It lives for the
 * process, so a long-running worker probes each origin once. {@see reset()} (`kernel.reset`) forgets it; the next
 * request then probes again, which costs at most one extra 404 against an older Beacon.
 */
final class IngestPathResolver implements ResetInterface
{
    public const MAX_ORIGINS = 32;

    /** @var array<string, bool> origin => true when the origin serves the versioned paths */
    private array $known = [];

    private readonly IngestPathMode $mode;

    /**
     * @param 'auto'|'legacy'|'versioned'|IngestPathMode $mode
     */
    public function __construct(IngestPathMode|string $mode = IngestPathMode::Auto)
    {
        $this->mode = $mode instanceof IngestPathMode ? $mode : IngestPathMode::from($mode);
    }

    /**
     * Configured mode.
     */
    public function mode(): IngestPathMode
    {
        return $this->mode;
    }

    /**
     * Whether the first request to `$origin` goes to the versioned path.
     */
    public function useVersioned(string $origin): bool
    {
        return match ($this->mode) {
            IngestPathMode::Versioned => true,
            IngestPathMode::Legacy    => false,
            IngestPathMode::Auto      => $this->known[$origin] ?? true,
        };
    }

    /**
     * Whether `$origin` is still unknown (auto mode, not probed yet): a request started now must keep its body for a
     * possible legacy retry.
     */
    public function isProbing(string $origin): bool
    {
        return $this->mode === IngestPathMode::Auto && !isset($this->known[$origin]);
    }

    /**
     * Report the HTTP status of a request sent to the versioned path. Returns true when the caller must send the same
     * request once more to the legacy path (auto mode, route missing, origin not known to serve the versioned paths).
     */
    public function versionedAnswered(string $origin, int $status): bool
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return false;
        }

        if (!IngestPaths::isRouteMissing($status)) {
            $this->learn($origin, true);

            return false;
        }

        return ($this->known[$origin] ?? null) !== true;
    }

    /**
     * Report the HTTP status of the legacy retry that followed a 404 / 405 on the versioned path.
     */
    public function legacyAnswered(string $origin, int $status): void
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return;
        }

        // Both paths missing: the project is unknown, not the path. Keep the canonical path and stop probing.
        $this->learn($origin, IngestPaths::isRouteMissing($status));
    }

    /**
     * Number of origins remembered (diagnostics / tests).
     */
    public function count(): int
    {
        return count($this->known);
    }

    /**
     * Forget every origin (`kernel.reset`).
     */
    public function reset(): void
    {
        $this->known = [];
    }

    private function learn(string $origin, bool $versioned): void
    {
        if (isset($this->known[$origin])) {
            return;
        }

        while (count($this->known) >= self::MAX_ORIGINS) {
            $oldest = array_key_first($this->known);
            unset($this->known[$oldest]);
        }

        // @igor-ignore - Server capability per origin (no request / user / tenant data), bounded to MAX_ORIGINS and cleared by reset().
        $this->known[$origin] = $versioned;
    }
}
