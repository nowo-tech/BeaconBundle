<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scheduler;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\Scheduler\Generator\MessageContext;

use function hash;
use function substr;

/**
 * Deterministic identifiers for one occurrence of a Symfony Scheduler recurring message.
 *
 * The run id is identical on every worker that handles the same occurrence, so Beacon dedupes
 * check-ins sent by several scheduler workers.
 */
final class ScheduledRun
{
    /**
     * `substr(sha256(schedule . "\n" . recurring_id . "\n" . triggered_at_ATOM_UTC), 0, 32)`.
     */
    public static function runId(MessageContext $context): string
    {
        return substr(
            hash('sha256', $context->name . "\n" . $context->id . "\n" . self::atom($context->triggeredAt)),
            0,
            32,
        );
    }

    /**
     * ATOM date normalized to UTC (workers with different default time zones agree on the value).
     */
    public static function atom(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }
}
