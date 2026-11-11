<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Ingest;

use Nowo\Beacon\Dsn\Dsn;

use function array_slice;
use function count;
use function rawurlencode;

/**
 * Remembers, per Beacon target (DSN origin + project id), whether that Beacon serves the versioned ingest paths
 * (`nowo_beacon.ingest_paths: auto`). Same state machine as beacon-php and beacon-js.
 *
 * In `auto` mode a target that is not known to serve the legacy paths only is sent to the versioned path. The answer
 * there decides what happens (audit LO-M1):
 *
 * - 2xx, or a 400 / 401 / 403 / 413 / 415 / 422 that Beacon's ingest controllers emit → the route exists: versioned;
 * - 404 / 405 → the route is missing (Beacon < 1.147): the same request goes once more to the legacy path. When the
 *   target was known as versioned (a Beacon downgrade, a stale entry, an application 404 on both paths) the entry is
 *   forgotten first (self-heal);
 * - anything else (408, 429, 5xx, other statuses from a proxy or a load balancer) or a network failure → nothing is
 *   learned and nothing is re-sent; the next request probes again.
 *
 * The legacy answer after a 404 / 405: 2xx or one of the Beacon 4xx above → legacy; 404 / 405 on both paths (unknown
 * project or command, not a path problem) → versioned; anything else → nothing learned. The newest answer always
 * replaces the older one. A 404 / 405 means nothing was processed, so the legacy resend never duplicates a request.
 *
 * The key includes the project id (same key as beacon-php and beacon-js), so a DSN with an unknown project (both paths
 * 404) cannot pin the versioned path for another project or tenant on the same older Beacon.
 *
 * Worker-safe (FrankenPHP worker mode without kernel reset): the map holds only a server capability (target → bool),
 * never keys, secrets, request, user or tenant data, and is bounded to {@see MAX_ENTRIES} entries (oldest dropped).
 * It is intentionally **not** reset with the kernel (audit LO-L4): it holds no request state, and a reset after every
 * Messenger message would cost one extra 404 per message against an older Beacon. A long-running worker or consumer
 * probes an older Beacon once (again only after a transient error or a self-heal). Under PHP-FPM the container lives
 * for one request, so every request that sends something probes again: set `ingest_paths: legacy` until Beacon is
 * 1.147. {@see reset()} forgets every target (tests, or after a Beacon upgrade without a restart).
 */
final class IngestPathResolver
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
     * Whether the DSN's target is still unknown (auto mode, no answer that proves a path yet).
     */
    public function isProbing(Dsn $dsn): bool
    {
        return $this->mode === IngestPathMode::Auto && !isset($this->known[$this->key($dsn)]);
    }

    /**
     * Whether a request to the versioned path may have to be re-sent to the legacy path (auto mode): its caller keeps
     * the body until the status is read.
     */
    public function mayFallBack(): bool
    {
        return $this->mode === IngestPathMode::Auto;
    }

    /**
     * Report the HTTP status of a request sent to the versioned path. Returns true when the caller must send the same
     * request once more to the legacy path (auto mode and 404 / 405, including on a target known as versioned).
     */
    public function versionedAnswered(Dsn $dsn, int $status): bool
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return false;
        }

        $key = $this->key($dsn);
        if (IngestPaths::isRouteMissing($status)) {
            // Self-heal: a target known as versioned that now has no such route is probed again.
            unset($this->known[$key]);

            return true;
        }

        if (IngestPaths::provesRoute($status)) {
            $this->learn($key, true);
        }

        return false;
    }

    /**
     * Report the HTTP status of the legacy retry that followed a 404 / 405 on the versioned path.
     */
    public function legacyAnswered(Dsn $dsn, int $status): void
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return;
        }

        if (IngestPaths::isRouteMissing($status)) {
            // Both paths missing: the project (or command) is unknown, not the path. Keep the canonical path.
            $this->learn($this->key($dsn), true);
        } elseif (IngestPaths::provesRoute($status)) {
            $this->learn($this->key($dsn), false);
        }
    }

    /**
     * Number of targets remembered (diagnostics / tests).
     */
    public function count(): int
    {
        return count($this->known);
    }

    /**
     * Forget every target (tests, or after a Beacon upgrade without a restart). Not tagged `kernel.reset`.
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

    /**
     * Remember the newest answer (it replaces an older one and becomes the newest entry).
     */
    private function learn(string $key, bool $versioned): void
    {
        unset($this->known[$key]);
        if (count($this->known) >= self::MAX_ENTRIES) {
            // Oldest first (insertion order); keys are URLs, never integers, so array_slice keeps them.
            // @igor-ignore - Bounded server capability per target (no request / user / tenant data), process-wide by design (LO-L4).
            $this->known = array_slice($this->known, 1 + count($this->known) - self::MAX_ENTRIES, null, true);
        }

        // @igor-ignore - Server capability per target (no keys, request, user or tenant data), bounded to MAX_ENTRIES; process-wide by design (LO-L4).
        $this->known[$key] = $versioned;
    }
}
