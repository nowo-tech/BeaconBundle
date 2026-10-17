<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

/**
 * Clients that can send Symfony Scheduler check-ins (`schedule_catalog` and `check_in` Envelope items).
 *
 * Kept separate from {@see BeaconClientInterface} so custom client implementations stay compatible.
 * Each item travels in its own Envelope; `before_send` is not applied (metadata only).
 */
interface CheckInCapableInterface
{
    /**
     * Send the schedule catalog (schedules and their recurring entries).
     *
     * @param list<array{name: string, entries: list<array<string, mixed>>}> $schedules
     *
     * @return string|null Event id when sent (null when disabled)
     */
    public function captureScheduleCatalog(array $schedules): ?string;

    /**
     * Send one check-in (`in_progress`, `ok` or `error`) for a scheduled run.
     *
     * @param array<string, mixed> $checkIn `run_id`, `schedule`, `recurring_id`, `status`, `triggered_at`, …
     *
     * @return string|null Event id when sent (null when disabled)
     */
    public function captureCheckIn(array $checkIn): ?string;
}
