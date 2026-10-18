<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Throwable;

use function array_shift;
use function array_slice;
use function count;
use function hrtime;
use function max;
use function min;

/**
 * Polls Beacon for remote Messenger commands (`messenger.remote_retry`).
 *
 * In workers (`poll_in_workers: true`) it runs on {@see WorkerRunningEvent} — between messages and
 * while idle, never while a message is being handled — and only when the next poll is due on a
 * monotonic clock. `nowo:beacon:messenger-commands` drives the same service from a dedicated process.
 *
 * Schedule: after a successful claim the next poll is `max(poll_interval, poll_after)`; network / 5xx
 * errors back off 30 → 60 → 120 → … 600 s; 429 / 503 honour `Retry-After`; 401 / 403 / 404 pause
 * polling in this process for one hour with a single warning. Results that could not be posted are
 * retried before the next claim (bounded). Never throws into the worker.
 *
 * Worker safety: the state (next poll time, backoff step, pause, unsent results) is per process and
 * holds no request data; it is reset on {@see WorkerStartedEvent}. The service is deliberately not
 * tagged `kernel.reset`: Messenger resets services after every message, which would turn the poll
 * schedule into one poll per message.
 */
final class RemoteCommandPoller implements EventSubscriberInterface
{
    public const BACKOFF_BASE = 30;

    public const BACKOFF_MAX = 600;

    public const AUTH_PAUSE = 3600;

    public const MAX_COMMANDS_PER_CLAIM = 1;

    public const MAX_PENDING_RESULTS = 20;

    public const MAX_RESULT_ATTEMPTS = 10;

    private ?float $nextPollAt = null;

    private int $backoffStep = 0;

    private ?float $pausedUntil = null;

    private bool $problemLogged = false;

    /** @var list<array{command: RemoteCommand, result: CommandResult, attempts: int}> */
    private array $pendingResults = [];

    /** @var Closure(): float */
    private readonly Closure $monotonic;

    /**
     * @param (Closure(): float)|null $monotonic Monotonic seconds (tests); defaults to `hrtime()`
     */
    public function __construct(
        private readonly RemoteCommandClient $client,
        private readonly FailedMessageCommandExecutor $executor,
        private readonly RemoteRetryCredentials $credentials,
        private readonly int $pollInterval = 30,
        private readonly ?LoggerInterface $logger = null,
        ?Closure $monotonic = null,
    ) {
        $this->monotonic = $monotonic ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->reset();
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $this->pollIfDue();
    }

    /**
     * Last chance to post results that could not be sent yet, then forget the process state.
     */
    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if ($this->pendingResults !== [] && $this->credentials->problem() === null) {
            try {
                $this->flushPendingResults();
            } catch (RemoteCommandException) {
                // Beacon will show "outcome unknown" for these commands.
            }
        }

        $this->reset();
    }

    /**
     * Poll when the next poll is due (null when it is not).
     *
     * @phpstan-impure
     */
    public function pollIfDue(): ?PollReport
    {
        if ($this->nextPollAt !== null && $this->now() < $this->nextPollAt) {
            return null;
        }

        return $this->poll();
    }

    /**
     * Seconds until the next poll is due (0 when due now).
     */
    public function secondsUntilNextPoll(): float
    {
        return $this->nextPollAt === null ? 0.0 : max(0.0, $this->nextPollAt - $this->now());
    }

    /**
     * Claim and execute commands now (ignoring the schedule, but not an auth pause). Never throws.
     *
     * @phpstan-impure
     */
    public function poll(): PollReport
    {
        $now     = $this->now();
        $problem = $this->credentials->problem();
        if ($problem !== null) {
            if (!$this->problemLogged) {
                $this->logger?->warning('Beacon remote Messenger commands are enabled but cannot run: {problem}', ['problem' => $problem]);
                // @igor-ignore - Per-process poll state (no request data), reset on WorkerStartedEvent.
                $this->problemLogged = true;
            }

            return $this->schedule(PollReport::STATUS_DISABLED, $now, self::AUTH_PAUSE, [], $problem);
        }

        if ($this->pausedUntil !== null && $now < $this->pausedUntil) {
            return $this->schedule(PollReport::STATUS_PAUSED, $now, $this->pausedUntil - $now);
        }
        // @igor-ignore - Per-process poll state (no request data), reset on WorkerStartedEvent.
        $this->pausedUntil = null;

        try {
            $this->flushPendingResults();
            $claim = $this->client->claim($this->executor->describeFailureTransports(), self::MAX_COMMANDS_PER_CLAIM);
        } catch (RemoteCommandException $e) {
            return $this->failure($e, $now);
        } catch (Throwable $e) {
            return $this->failure(new RemoteCommandException(RemoteCommandException::KIND_UNAVAILABLE, $e::class), $now);
        }

        $executed = [];
        foreach (array_slice($claim->commands, 0, self::MAX_COMMANDS_PER_CLAIM) as $command) {
            try {
                $result = $this->executor->execute($command);
            } catch (Throwable $e) {
                $result = CommandResult::failed(CommandResult::REASON_INTERNAL_ERROR, new DateTimeImmutable(), $e);
            }

            $executed[] = [
                'id'                => $command->id,
                'action'            => $command->action,
                'failure_transport' => $command->failureTransport,
                'message_id'        => $command->messageId,
                'result'            => $result->summary(),
                'reported'          => $this->postResult($command, $result),
            ];
        }

        // @igor-ignore - Per-process poll state (no request data), reset on WorkerStartedEvent.
        $this->backoffStep = 0;

        return $this->schedule(PollReport::STATUS_OK, $now, max($this->pollInterval, $claim->pollAfter ?? 0), $executed);
    }

    /**
     * Forget the per-process state (worker start / stop).
     */
    public function reset(): void
    {
        // @igor-ignore - Per-process poll state reset.
        $this->nextPollAt = null;
        // @igor-ignore - Per-process poll state reset.
        $this->backoffStep = 0;
        // @igor-ignore - Per-process poll state reset.
        $this->pausedUntil = null;
        // @igor-ignore - Per-process poll state reset.
        $this->problemLogged = false;
        // @igor-ignore - Per-process poll state reset.
        $this->pendingResults = [];
    }

    /**
     * Post one result; keep it for a later attempt when Beacon is unreachable.
     *
     * @return bool Whether Beacon accepted it
     */
    private function postResult(RemoteCommand $command, CommandResult $result): bool
    {
        try {
            $this->client->postResult($command, $result);

            return true;
        } catch (RemoteCommandException $e) {
            if ($e->kind === RemoteCommandException::KIND_REJECTED) {
                $this->logger?->warning('Beacon refused the result of remote command {id} ({detail}).', ['id' => $command->id, 'detail' => $e->getMessage()]);

                return false;
            }

            $this->keepPending($command, $result, 1);
            if ($e->kind === RemoteCommandException::KIND_AUTH) {
                $this->pause();
            }

            return false;
        }
    }

    /**
     * @throws RemoteCommandException when Beacon refuses the key or is unreachable (remaining results stay pending)
     */
    private function flushPendingResults(): void
    {
        while ($this->pendingResults !== []) {
            ['command' => $command, 'result' => $result, 'attempts' => $attempts] = $this->pendingResults[0];

            try {
                $this->client->postResult($command, $result);
            } catch (RemoteCommandException $e) {
                if ($e->kind !== RemoteCommandException::KIND_REJECTED && $attempts < self::MAX_RESULT_ATTEMPTS) {
                    // @igor-ignore - Per-process unsent results (ids / codes only), reset on WorkerStartedEvent.
                    $this->pendingResults[0]['attempts'] = $attempts + 1;

                    throw $e;
                }

                $this->logger?->warning('Beacon remote command {id}: result dropped ({detail}).', ['id' => $command->id, 'detail' => $e->getMessage()]);
            }

            // @igor-ignore - Per-process unsent results (ids / codes only), reset on WorkerStartedEvent.
            array_shift($this->pendingResults);
        }
    }

    private function keepPending(RemoteCommand $command, CommandResult $result, int $attempts): void
    {
        if (count($this->pendingResults) >= self::MAX_PENDING_RESULTS) {
            $this->logger?->warning('Beacon remote command {id}: too many unsent results, result dropped.', ['id' => $command->id]);

            return;
        }

        // @igor-ignore - Per-process unsent results (ids / codes only), reset on WorkerStartedEvent.
        $this->pendingResults[] = ['command' => $command, 'result' => $result, 'attempts' => $attempts];
    }

    private function failure(RemoteCommandException $e, float $now): PollReport
    {
        switch ($e->kind) {
            case RemoteCommandException::KIND_AUTH:
                $this->pause();

                return $this->schedule(PollReport::STATUS_AUTH_ERROR, $now, self::AUTH_PAUSE, [], $e->getMessage());

            case RemoteCommandException::KIND_RATE_LIMITED:
                $delay = $e->retryAfter ?? $this->nextBackoff();

                return $this->schedule(PollReport::STATUS_RATE_LIMITED, $now, max($this->pollInterval, $delay), [], $e->getMessage());

            default:
                if ($this->backoffStep === 0) {
                    $this->logger?->warning('Beacon remote Messenger commands: claim failed ({detail}), backing off.', ['detail' => $e->getMessage()]);
                }

                return $this->schedule(PollReport::STATUS_UNAVAILABLE, $now, $this->nextBackoff(), [], $e->getMessage());
        }
    }

    private function pause(): void
    {
        if ($this->pausedUntil === null) {
            $this->logger?->warning('Beacon refused the worker key or does not support remote Messenger commands (HTTP 401 / 403 / 404): polling paused for one hour. Check BEACON_WORKER_KEY and the Beacon version.');
        }

        // @igor-ignore - Per-process poll state (no request data), reset on WorkerStartedEvent.
        $this->pausedUntil = $this->now() + self::AUTH_PAUSE;
    }

    /**
     * Current backoff delay, then one step further (30 → 60 → … 600 s).
     */
    private function nextBackoff(): int
    {
        $delay = min(self::BACKOFF_MAX, max($this->pollInterval, self::BACKOFF_BASE * (2 ** min($this->backoffStep, 10))));
        // @igor-ignore - Per-process poll state (no request data), reset on WorkerStartedEvent.
        ++$this->backoffStep;

        return $delay;
    }

    /**
     * @param list<array{id: string, action: string, failure_transport: string, message_id: string, result: string, reported: bool}> $commands
     */
    private function schedule(string $status, float $now, float|int $delay, array $commands = [], ?string $detail = null): PollReport
    {
        // @igor-ignore - Per-process poll state (no request data), reset on WorkerStartedEvent.
        $this->nextPollAt = $now + $delay;

        return new PollReport($status, (float) $delay, $commands, $detail);
    }

    private function now(): float
    {
        return ($this->monotonic)();
    }
}
