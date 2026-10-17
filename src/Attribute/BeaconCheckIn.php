<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Attribute;

use Attribute;

/**
 * Per-message Scheduler check-in settings (Symfony Scheduler recurring messages).
 *
 * Put it on the message class dispatched by a schedule. It wins over the
 * `nowo_beacon.scheduler.check_ins.max_runtime` / `ignore` configuration for the same class.
 *
 * - `maxRuntime`: seconds (1 … 604 800) after which Beacon reports the run as too long; null = no limit.
 *   Values outside the range are ignored (treated as null). The Beacon UI value wins server side.
 * - `enabled: false`: the message is never checked in nor listed in the schedule catalog.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BeaconCheckIn
{
    public function __construct(
        public readonly ?int $maxRuntime = null,
        public readonly bool $enabled = true,
    ) {
    }
}
