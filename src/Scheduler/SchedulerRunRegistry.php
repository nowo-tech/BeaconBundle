<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scheduler;

use Symfony\Contracts\Service\ResetInterface;

use function array_key_first;
use function count;

/**
 * Per-process state of Scheduler check-ins: when each running occurrence started and the
 * failure event sent for it (so the `error` check-in can link `failure_event_id`).
 *
 * Bounded to {@see self::MAX_RUNS} entries per map, cleared when a worker starts / stops and on
 * `kernel.reset` (Messenger resets services after each handled message; FrankenPHP after each request).
 */
final class SchedulerRunRegistry implements ResetInterface
{
    public const MAX_RUNS = 100;

    /** @var array<string, float> run id => started at (Unix float) */
    private array $startedAt = [];

    /** @var array<string, string> run id => failure event id */
    private array $failureEventIds = [];

    /**
     * Remember that a run started (the oldest entry is dropped beyond {@see self::MAX_RUNS}).
     */
    public function start(string $runId, float $startedAt): void
    {
        if (!isset($this->startedAt[$runId]) && count($this->startedAt) >= self::MAX_RUNS) {
            // @igor-ignore - Bounded per-run map, cleared on kernel.reset and worker start / stop.
            unset($this->startedAt[array_key_first($this->startedAt)]);
        }

        // @igor-ignore - Bounded per-run map, cleared on kernel.reset and worker start / stop.
        $this->startedAt[$runId] = $startedAt;
    }

    /**
     * Forget a run and return when it started (null when unknown).
     */
    public function finish(string $runId): ?float
    {
        $startedAt = $this->startedAt[$runId] ?? null;
        // @igor-ignore - Bounded per-run map, cleared on kernel.reset and worker start / stop.
        unset($this->startedAt[$runId]);

        return $startedAt;
    }

    /**
     * Remember the event id of the exception reported for a failed run.
     */
    public function rememberFailureEvent(string $runId, string $eventId): void
    {
        if (!isset($this->failureEventIds[$runId]) && count($this->failureEventIds) >= self::MAX_RUNS) {
            // @igor-ignore - Bounded per-run map, cleared on kernel.reset and worker start / stop.
            unset($this->failureEventIds[array_key_first($this->failureEventIds)]);
        }

        // @igor-ignore - Bounded per-run map, cleared on kernel.reset and worker start / stop.
        $this->failureEventIds[$runId] = $eventId;
    }

    /**
     * Return and forget the failure event id of a run.
     */
    public function takeFailureEvent(string $runId): ?string
    {
        $eventId = $this->failureEventIds[$runId] ?? null;
        // @igor-ignore - Bounded per-run map, cleared on kernel.reset and worker start / stop.
        unset($this->failureEventIds[$runId]);

        return $eventId;
    }

    /**
     * Number of runs currently tracked as started (diagnostics / tests).
     */
    public function runningCount(): int
    {
        return count($this->startedAt);
    }

    public function reset(): void
    {
        $this->startedAt       = [];
        $this->failureEventIds = [];
    }
}
