<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scheduler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Throwable;

use function array_keys;
use function count;
use function date_default_timezone_get;
use function in_array;
use function is_string;
use function max;
use function mb_substr;
use function round;
use function sort;

/**
 * Turns Symfony Scheduler schedules into `schedule_catalog` entries.
 *
 * Message classes are only read from {@see StaticMessageProvider} (the default for
 * `RecurringMessage::every()` / `cron()` / `#[AsCronTask]` / `#[AsPeriodicTask]`); callback and custom
 * providers are never invoked (`message_class` and `description` null). Each entry carries a human
 * `description` ({@see TaskDescriptionResolver}, string|null). Triggers are inspected through their public API
 * plus read-only reflection for the cron time zone; periodic triggers are probed on a clone so the
 * schedule's own anchor is never moved.
 */
final class ScheduleCatalogBuilder
{
    public const MAX_SCHEDULES = 20;
    public const MAX_ENTRIES   = 200;

    private readonly ClockInterface $clock;

    private readonly TaskDescriptionResolver $descriptions;

    /**
     * @param ServiceProviderInterface<ScheduleProviderInterface>|null $providers schedule providers by schedule name
     */
    public function __construct(
        private readonly CheckInPolicy $policy,
        private readonly ?ServiceProviderInterface $providers = null,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        ?TaskDescriptionResolver $descriptions = null,
    ) {
        $this->descriptions = $descriptions ?? new TaskDescriptionResolver($policy);
        $this->clock        = $clock ?? new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
    }

    /**
     * Registered schedule names, sorted.
     *
     * @return list<string>
     */
    public function scheduleNames(): array
    {
        $names = [];
        foreach (array_keys($this->providers?->getProvidedServices() ?? []) as $name) {
            $names[] = (string) $name;
        }
        sort($names);

        return $names;
    }

    /**
     * Catalog of the given schedules (all when null). A schedule whose provider throws is skipped
     * (never sent empty, so Beacon does not retire its tasks).
     *
     * @param list<string>|null $only
     *
     * @return list<array{name: string, entries: list<array<string, mixed>>}>
     */
    public function build(?array $only = null): array
    {
        $catalog = [];
        foreach ($this->scheduleNames() as $name) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            if (count($catalog) >= self::MAX_SCHEDULES) {
                break;
            }

            try {
                $provider = $this->providers?->get($name);
                if (!$provider instanceof ScheduleProviderInterface) {
                    continue;
                }
                $messages = $provider->getSchedule()->getRecurringMessages();
            } catch (Throwable $e) {
                $this->logger?->warning('Beacon: cannot read schedule "{schedule}" for the check-in catalog: {error}', [
                    'schedule' => $name,
                    'error'    => $e->getMessage(),
                ]);

                continue;
            }

            $catalog[] = [
                'name'    => mb_substr($name, 0, 120),
                'entries' => $this->entries($messages),
            ];
        }

        return $catalog;
    }

    /**
     * @param array<RecurringMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $messages): array
    {
        // Schedule::add() already rejects duplicated recurring ids.
        $entries = [];
        foreach ($messages as $recurring) {
            if (count($entries) >= self::MAX_ENTRIES) {
                break;
            }

            $entry = $this->entry(mb_substr($recurring->getId(), 0, 64), $recurring);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null null when the message opts out of check-ins
     */
    private function entry(string $id, RecurringMessage $recurring): ?array
    {
        $messageClass = null;
        $command      = null;
        $maxRuntime   = null;
        $description  = null;

        $provider = $recurring->getProvider();
        if ($provider instanceof StaticMessageProvider) {
            $first = null;
            foreach ($provider->getMessages($this->dummyContext($id, $recurring)) as $message) {
                $first = $message;
                break;
            }
            if ($first !== null) {
                ['message_class' => $messageClass, 'command' => $command] = CheckInPolicy::describe($first);
                if (!$this->policy->isEnabled($messageClass, $command)) {
                    return null;
                }
                $maxRuntime  = $this->policy->maxRuntime($messageClass, $command);
                $description = $this->descriptions->describe($first);
            }
        }

        $trigger = $recurring->getTrigger();
        $entry   = [
            'recurring_id'  => $id,
            'message_class' => $messageClass !== null ? mb_substr($messageClass, 0, 255) : null,
        ];
        if ($command !== null) {
            $entry['command'] = $command;
        }
        $entry['description']         = $description;
        $entry['trigger']             = $this->describeTrigger($trigger);
        $entry['next_run_at']         = $this->nextRunAt($trigger);
        $entry['max_runtime_seconds'] = $maxRuntime;

        return $entry;
    }

    /**
     * @return array{kind: string, expression: string|null, timezone: string|null, interval_seconds: int|null, description: string}
     */
    private function describeTrigger(TriggerInterface $trigger): array
    {
        $inner = $trigger instanceof AbstractDecoratedTrigger ? $trigger->inner() : $trigger;

        $kind       = 'other';
        $expression = null;
        $timezone   = null;
        $interval   = null;

        if ($inner instanceof CronExpressionTrigger) {
            $kind       = 'cron';
            $expression = mb_substr((string) $inner, 0, 255);
            $timezone   = mb_substr($this->cronTimezone($inner) ?? date_default_timezone_get(), 0, 64);
        } elseif ($inner instanceof PeriodicalTrigger) {
            $kind     = 'periodic';
            $interval = $this->periodicInterval($inner);
        }

        return [
            'kind'             => $kind,
            'expression'       => $expression,
            'timezone'         => $timezone,
            'interval_seconds' => $interval,
            'description'      => mb_substr((string) $trigger, 0, 255),
        ];
    }

    /**
     * Next run date as computed by the trigger itself (ATOM, UTC); null when none or on error.
     *
     * A periodic trigger whose anchor (`from`) is not set yet is probed on a clone: calling it
     * directly would anchor the real schedule on "now" before the worker does.
     */
    private function nextRunAt(TriggerInterface $trigger): ?string
    {
        $now = $this->clock->now();

        try {
            $inner = $trigger instanceof AbstractDecoratedTrigger ? $trigger->inner() : $trigger;
            if ($inner instanceof PeriodicalTrigger && !$this->periodicAnchored($inner)) {
                $next = (clone $inner)->getNextRunDate($now);
            } else {
                $next = $trigger->getNextRunDate($now);
            }
        } catch (Throwable) {
            return null;
        }

        return $next instanceof DateTimeImmutable ? ScheduledRun::atom($next) : null;
    }

    private function periodicInterval(PeriodicalTrigger $trigger): ?int
    {
        try {
            $probe  = clone $trigger;
            $first  = $probe->getNextRunDate($this->clock->now());
            $second = $first instanceof DateTimeImmutable ? $probe->getNextRunDate($first) : null;
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        if (!$first instanceof DateTimeImmutable || !$second instanceof DateTimeImmutable) {
            return null;
        }

        return max(1, (int) round((float) $second->format('U.u') - (float) $first->format('U.u')));
    }

    private function periodicAnchored(PeriodicalTrigger $trigger): bool
    {
        try {
            return (new ReflectionProperty(PeriodicalTrigger::class, 'from'))->getValue($trigger) !== null;
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    private function cronTimezone(CronExpressionTrigger $trigger): ?string
    {
        try {
            $timezone = (new ReflectionProperty(CronExpressionTrigger::class, 'timezone'))->getValue($trigger);
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    /**
     * Context handed to {@see StaticMessageProvider::getMessages()} (it ignores it).
     */
    private function dummyContext(string $id, RecurringMessage $recurring): MessageContext
    {
        return new MessageContext('', $id, $recurring->getTrigger(), $this->clock->now());
    }
}
