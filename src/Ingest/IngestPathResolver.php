<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Ingest;

use Nowo\Beacon\Dsn\Dsn;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_first;
use function count;
use function rawurlencode;

/**
 * Remembers, per Beacon target (DSN origin + project id), whether that Beacon serves the versioned ingest paths
 * (`nowo_beacon.ingest_paths: auto`).
 *
 * In `auto` mode an unknown target is tried on the versioned path. A 404 / 405 there means an older Beacon: the same
 * request goes once more to the legacy path, and the answer is remembered:
 *
 * - versioned path answered anything else (2xx, 400, 401, 403, 413, 429, 5xx…) → versioned;
 * - legacy path answered something other than 404 / 405 → legacy;
 * - both answered 404 / 405 (unknown project, not a path problem) → versioned, so the target is probed only once;
 * - a network failure (no HTTP answer) → nothing is learned and nothing is re-sent.
 *
 * The key includes the project id (same key as beacon-php and beacon-js), so a DSN with an unknown project (both paths
 * 404) cannot pin the versioned path for another project or tenant on the same older Beacon.
 *
 * Worker-safe (FrankenPHP worker mode without kernel reset): the map holds only a server capability (target → bool),
 * never keys, secrets, request, user or tenant data, and is bounded to {@see MAX_ENTRIES} entries (oldest dropped). It
 * lives for the process, so a long-running worker probes each target once. {@see reset()} (`kernel.reset`) forgets it;
 * the next request then probes again, which costs at most one extra 404 against an older Beacon.
 */
final class IngestPathResolver implements ResetInterface
{
    public const MAX_ENTRIES = 32;

    /**
     * @deprecated use {@see MAX_ENTRIES}; kept as an alias (the cache is keyed by origin + project id, not by origin)
     */
    public const MAX_ORIGINS = self::MAX_ENTRIES;

    /** @var array<string, bool> target (origin + project id) => true when it serves the versioned paths */
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
     * Whether the first request to the DSN's Beacon target goes to the versioned path.
     */
    public function useVersioned(Dsn $dsn): bool
    {
        return match ($this->mode) {
            IngestPathMode::Versioned => true,
            IngestPathMode::Legacy    => false,
            IngestPathMode::Auto      => $this->known[$this->key($dsn)] ?? true,
        };
    }

    /**
     * Whether the DSN's target is still unknown (auto mode, not probed yet): a request started now must keep its body
     * for a possible legacy retry.
     */
    public function isProbing(Dsn $dsn): bool
    {
        return $this->mode === IngestPathMode::Auto && !isset($this->known[$this->key($dsn)]);
    }

    /**
     * Report the HTTP status of a request sent to the versioned path. Returns true when the caller must send the same
     * request once more to the legacy path (auto mode, route missing, target not known to serve the versioned paths).
     */
    public function versionedAnswered(Dsn $dsn, int $status): bool
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return false;
        }

        $key = $this->key($dsn);
        if (!IngestPaths::isRouteMissing($status)) {
            $this->learn($key, true);

            return false;
        }

        return ($this->known[$key] ?? null) !== true;
    }

    /**
     * Report the HTTP status of the legacy retry that followed a 404 / 405 on the versioned path.
     */
    public function legacyAnswered(Dsn $dsn, int $status): void
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return;
        }

        // Both paths missing: the project is unknown, not the path. Keep the canonical path and stop probing.
        $this->learn($this->key($dsn), IngestPaths::isRouteMissing($status));
    }

    /**
     * Number of targets remembered (diagnostics / tests).
     */
    public function count(): int
    {
        return count($this->known);
    }

    /**
     * Forget every target (`kernel.reset`).
     */
    public function reset(): void
    {
        $this->known = [];
    }

    /**
     * Cache key: origin + project id (same as beacon-php). Never the keys or the secret.
     */
    private function key(Dsn $dsn): string
    {
        return $dsn->getOrigin() . '/' . rawurlencode($dsn->getProjectId());
    }

    private function learn(string $key, bool $versioned): void
    {
        if (isset($this->known[$key])) {
            return;
        }

        while (count($this->known) >= self::MAX_ENTRIES) {
            $oldest = array_key_first($this->known);
            unset($this->known[$oldest]);
        }

        // @igor-ignore - Server capability per target (no keys, request, user or tenant data), bounded to MAX_ENTRIES and cleared by reset().
        $this->known[$key] = $versioned;
    }
}
