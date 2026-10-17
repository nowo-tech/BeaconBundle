<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use DateTimeImmutable;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\CheckInCapableInterface;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Nowo\BeaconBundle\Scheduler\ScheduledRun;
use Nowo\BeaconBundle\Scheduler\SchedulerRunRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Throwable;

use function in_array;
use function max;
use function mb_substr;
use function round;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Symfony Scheduler check-ins (opt-in `nowo_beacon.scheduler.check_ins.enabled`).
 *
 * - Worker start (transports `scheduler_<name>`): one `schedule_catalog` Envelope for those schedules,
 *   re-sent every `catalog_refresh_hours` on {@see WorkerRunningEvent}.
 * - Each scheduled run ({@see ScheduledStamp}): `in_progress` on receive, `ok` on handled, `error` on
 *   failure (with or without retry), each in its own `check_in` Envelope with a deterministic run id.
 *
 * Message priority -512: after {@see BeaconMessengerFailedListener} (0) so `failure_event_id` is known,
 * before the transaction listener (-1024) and the async flush (-2048). Message bodies are never read
 * (only the class and, for `RunCommandMessage`, the command name). A Beacon outage never throws into
 * the worker. In async mode pending POSTs are flushed right after the catalog and the `in_progress`
 * check-in so Beacon sees a long run while it is still running.
 */
final class BeaconSchedulerCheckInListener implements EventSubscriberInterface
{
    public const PRIORITY = -512;

    private const TRANSPORT_PREFIX = 'scheduler_';

    private readonly ClockInterface $clock;

    /** @var list<string> schedules consumed by the current worker */
    private array $workerSchedules = [];

    private ?float $lastCatalogAt = null;

    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly ScheduleCatalogBuilder $catalogBuilder,
        private readonly CheckInPolicy $policy,
        private readonly SchedulerRunRegistry $runs,
        private readonly int $catalogRefreshHours = 24,
        private readonly ?PendingTransportRegistry $pendingTransports = null,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class         => 'onWorkerStarted',
            WorkerRunningEvent::class         => 'onWorkerRunning',
            WorkerStoppedEvent::class         => 'onWorkerStopped',
            WorkerMessageReceivedEvent::class => ['onMessageReceived', self::PRIORITY],
            WorkerMessageHandledEvent::class  => ['onMessageHandled', self::PRIORITY],
            WorkerMessageFailedEvent::class   => ['onMessageFailed', self::PRIORITY],
        ];
    }

    /**
     * Send the catalog of the schedules this worker consumes.
     */
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->runs->reset();
        $known     = $this->catalogBuilder->scheduleNames();
        $schedules = [];
        foreach ($event->getWorker()->getMetadata()->getTransportNames() as $transport) {
            if (!str_starts_with($transport, self::TRANSPORT_PREFIX)) {
                continue;
            }
            $name = substr($transport, strlen(self::TRANSPORT_PREFIX));
            if (in_array($name, $known, true) && !in_array($name, $schedules, true)) {
                $schedules[] = $name;
            }
        }

        // @igor-ignore - Worker-lifetime state (schedules of this worker), cleared on WorkerStoppedEvent.
        $this->workerSchedules = $schedules;
        // @igor-ignore - Worker-lifetime state (last catalog time), cleared on WorkerStoppedEvent.
        $this->lastCatalogAt = null;

        if ($schedules !== []) {
            $this->sendCatalog();
        }
    }

    /**
     * Re-send the catalog every `catalog_refresh_hours`.
     */
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->workerSchedules === [] || $this->lastCatalogAt === null) {
            return;
        }

        if ($this->now() - $this->lastCatalogAt >= $this->catalogRefreshHours * 3600) {
            $this->sendCatalog();
        }
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->runs->reset();
        // @igor-ignore - Worker-lifetime state, cleared here.
        $this->workerSchedules = [];
        // @igor-ignore - Worker-lifetime state, cleared here.
        $this->lastCatalogAt = null;
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$event->shouldHandle()) {
            return; // cancelled (e.g. PreRunEvent::shouldCancel()): no run
        }

        $context = $this->checkedInContext($event->getEnvelope());
        if (!$context instanceof MessageContext) {
            return;
        }

        $runId     = ScheduledRun::runId($context);
        $startedAt = $this->now();
        $this->runs->start($runId, $startedAt);

        $this->sendCheckIn([
            'run_id'       => $runId,
            'schedule'     => mb_substr($context->name, 0, 120),
            'recurring_id' => mb_substr($context->id, 0, 64),
            'status'       => 'in_progress',
            'triggered_at' => ScheduledRun::atom($context->triggeredAt),
            'started_at'   => $startedAt,
            'next_run_at'  => $this->nextRunAt($context),
        ]);
        $this->flush();
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->finish($event->getEnvelope(), 'ok');
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->finish($event->getEnvelope(), 'error');
    }

    private function finish(Envelope $envelope, string $status): void
    {
        $context = $this->checkedInContext($envelope);
        if (!$context instanceof MessageContext) {
            return;
        }

        $runId          = ScheduledRun::runId($context);
        $startedAt      = $this->runs->finish($runId);
        $failureEventId = $this->runs->takeFailureEvent($runId);
        $finishedAt     = $this->now();

        $this->sendCheckIn([
            'run_id'           => $runId,
            'schedule'         => mb_substr($context->name, 0, 120),
            'recurring_id'     => mb_substr($context->id, 0, 64),
            'status'           => $status,
            'triggered_at'     => ScheduledRun::atom($context->triggeredAt),
            'started_at'       => $startedAt,
            'finished_at'      => $finishedAt,
            'duration_ms'      => $startedAt !== null ? max(0, (int) round(($finishedAt - $startedAt) * 1000)) : null,
            'next_run_at'      => $this->nextRunAt($context),
            'failure_event_id' => $status === 'error' ? $failureEventId : null,
        ]);
    }

    /**
     * Scheduler context of an envelope whose message is checked in (null otherwise).
     */
    private function checkedInContext(Envelope $envelope): ?MessageContext
    {
        $stamp = $envelope->last(ScheduledStamp::class);
        if (!$stamp instanceof ScheduledStamp || !$this->capable() instanceof CheckInCapableInterface) {
            return null;
        }

        ['message_class' => $class, 'command' => $command] = CheckInPolicy::describe($envelope->getMessage());

        return $this->policy->isEnabled($class, $command) ? $stamp->messageContext : null;
    }

    private function nextRunAt(MessageContext $context): ?string
    {
        return $context->nextTriggerAt instanceof DateTimeImmutable ? ScheduledRun::atom($context->nextTriggerAt) : null;
    }

    /**
     * The client when it can send check-ins and is enabled.
     */
    private function capable(): ?CheckInCapableInterface
    {
        return $this->client instanceof CheckInCapableInterface && $this->client->isEnabled() ? $this->client : null;
    }

    private function sendCatalog(): void
    {
        // @igor-ignore - Worker-lifetime state (last catalog time), cleared on WorkerStoppedEvent.
        $this->lastCatalogAt = $this->now();

        $client = $this->capable();
        if (!$client instanceof CheckInCapableInterface) {
            return;
        }

        try {
            $catalog = $this->catalogBuilder->build($this->workerSchedules);
            if ($catalog !== []) {
                $client->captureScheduleCatalog($catalog);
            }
        } catch (Throwable $e) {
            $this->logger?->warning('Beacon: schedule catalog not sent: {error}', ['error' => $e->getMessage()]);
        }

        $this->flush();
    }

    /**
     * @param array<string, mixed> $checkIn
     */
    private function sendCheckIn(array $checkIn): void
    {
        try {
            $this->capable()?->captureCheckIn($checkIn);
        } catch (Throwable $e) {
            $this->logger?->warning('Beacon: scheduler check-in not sent: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function flush(): void
    {
        try {
            $this->pendingTransports?->flush();
        } catch (Throwable $e) {
            $this->logger?->warning('Beacon: flushing pending envelopes failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
