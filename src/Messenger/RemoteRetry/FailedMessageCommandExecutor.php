<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use DateTimeImmutable;
use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

use function array_values;
use function class_exists;
use function count;
use function hash;
use function hash_equals;
use function in_array;
use function strtolower;

/**
 * Verifies and executes one remote command against a failure transport.
 *
 * Verification order (each failure → `failed` + reason, nothing executed): signature
 * (`hash_equals`), project, expiry (`expires_at` > now − 60 s), action in `allowed_actions`,
 * transport in `failure_transports` and listable, then the executed-ids cache (24 h): a command id
 * seen before is never executed again — its stored result is returned so the same result can be
 * re-posted (idempotent on Beacon).
 *
 * - retry: `find()` → re-send the envelope to its **original transport's sender** without
 *   `SentToFailureTransportStamp` / `RedeliveryStamp` / the failure transport's id and delay stamps
 *   (Beacon's trace stamp is kept) → `reject()` it from the failure transport. The message is **not
 *   handled here**: the normal worker of the original transport handles it.
 * - discard: `find()` → `reject()`.
 *
 * Results carry codes and the exception class only; logs carry ids only.
 */
final class FailedMessageCommandExecutor
{
    public const EXECUTED_TTL = 86400;

    public const CLOCK_SKEW = 60;

    private const CACHE_PREFIX = 'nowo_beacon.remote_command.';

    private const IN_PROGRESS = 'in_progress';

    /** Bounded in-process fallback when no cache pool is available. */
    private const MEMORY_MAX = 1000;

    /** @var array<string, array{value: mixed, expires: int}> */
    private array $memory = [];

    /** @var list<string> */
    private readonly array $allowedActions;

    /** @var list<string> */
    private readonly array $failureTransports;

    /**
     * @param list<string> $allowedActions Subset of {@see RemoteCommand::ACTIONS}
     * @param list<string> $failureTransports Failure transports commands may act on
     */
    public function __construct(
        private readonly RemoteRetryCredentials $credentials,
        private readonly ?ContainerInterface $receiverLocator,
        array $allowedActions,
        array $failureTransports,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->allowedActions    = array_values($allowedActions);
        $this->failureTransports = array_values($failureTransports);
    }

    /**
     * Allowed failure transports and whether each can find messages by id (sent with each claim).
     *
     * @return list<array{name: string, listable: bool}>
     */
    public function describeFailureTransports(): array
    {
        $described = [];
        foreach ($this->failureTransports as $name) {
            $described[] = ['name' => $name, 'listable' => $this->listableReceiver($name) instanceof ListableReceiverInterface];
        }

        return $described;
    }

    public function execute(RemoteCommand $command): CommandResult
    {
        $rejection = $this->verify($command);
        if ($rejection instanceof CommandResult) {
            $this->logger?->warning('Beacon remote command {id} refused: {reason}.', [
                'id'     => $command->id,
                'reason' => $rejection->reasonCode,
            ]);

            return $rejection;
        }

        $stored = $this->recall($command->id);
        if ($stored !== null) {
            $previous = CommandResult::fromStored($stored);
            if ($previous instanceof CommandResult) {
                $this->logger?->warning('Beacon remote command {id} was already executed: not executed again.', ['id' => $command->id]);

                return $previous;
            }

            // Started by a process that died before storing the outcome: never run it twice.
            $this->logger?->warning('Beacon remote command {id} was started before and its outcome is unknown: not executed again.', ['id' => $command->id]);

            return CommandResult::failed(CommandResult::REASON_INTERNAL_ERROR, $this->now());
        }

        $this->remember($command->id, self::IN_PROGRESS);

        /** @var ListableReceiverInterface $receiver verified above */
        $receiver = $this->listableReceiver($command->failureTransport);
        $result   = $command->action === RemoteCommand::ACTION_RETRY
            ? $this->retry($receiver, $command->messageId)
            : $this->discard($receiver, $command->messageId);

        $this->remember($command->id, $result->toStored());
        $this->logger?->info('Beacon remote command {id} ({action} {transport} #{message_id}): {result}.', [
            'id'         => $command->id,
            'action'     => $command->action,
            'transport'  => $command->failureTransport,
            'message_id' => $command->messageId,
            'result'     => $result->summary(),
        ]);

        return $result;
    }

    private function verify(RemoteCommand $command): ?CommandResult
    {
        $key = $this->credentials->workerKey();
        if (!$key instanceof WorkerKey) {
            return CommandResult::failed(CommandResult::REASON_INTERNAL_ERROR, $this->now());
        }

        if (!hash_equals($command->expectedSignature($key->signingKey()), strtolower($command->signature))
            || !$this->credentials->matchesProject($command->project)
        ) {
            return CommandResult::failed(CommandResult::REASON_SIGNATURE_INVALID, $this->now());
        }

        if ($this->isExpired($command->expiresAt)) {
            return CommandResult::failed(CommandResult::REASON_EXPIRED, $this->now());
        }

        if (!in_array($command->action, RemoteCommand::ACTIONS, true) || !in_array($command->action, $this->allowedActions, true)) {
            return CommandResult::failed(CommandResult::REASON_ACTION_NOT_ALLOWED, $this->now());
        }

        if (!in_array($command->failureTransport, $this->failureTransports, true)) {
            return CommandResult::failed(CommandResult::REASON_TRANSPORT_NOT_ALLOWED, $this->now());
        }

        if (!$this->listableReceiver($command->failureTransport) instanceof ListableReceiverInterface) {
            return CommandResult::failed(CommandResult::REASON_TRANSPORT_NOT_LISTABLE, $this->now());
        }

        return null;
    }

    private function retry(ListableReceiverInterface $receiver, string $messageId): CommandResult
    {
        try {
            $envelope = $receiver->find($messageId);
        } catch (Throwable $e) {
            return CommandResult::failed(CommandResult::REASON_INTERNAL_ERROR, $this->now(), $e);
        }

        $failure = $envelope?->last(SentToFailureTransportStamp::class);
        if (!$envelope instanceof Envelope || !$failure instanceof SentToFailureTransportStamp) {
            return CommandResult::failed(CommandResult::REASON_NOT_FOUND, $this->now());
        }

        // A message whose class / body cannot be decoded any more cannot be re-sent safely.
        if (class_exists(MessageDecodingFailedStamp::class) && $envelope->last(MessageDecodingFailedStamp::class) instanceof StampInterface) {
            return CommandResult::failed(CommandResult::REASON_REQUEUE_FAILED, $this->now());
        }

        $sender = $this->sender($failure->getOriginalReceiverName());
        if (!$sender instanceof SenderInterface) {
            return CommandResult::failed(CommandResult::REASON_REQUEUE_FAILED, $this->now());
        }

        $requeue = $envelope
            ->withoutAll(SentToFailureTransportStamp::class)
            ->withoutAll(RedeliveryStamp::class)
            ->withoutAll(TransportMessageIdStamp::class)
            ->withoutAll(DelayStamp::class)
            ->withoutStampsOfType(NonSendableStampInterface::class);

        try {
            $sender->send($requeue);
        } catch (Throwable $e) {
            return CommandResult::failed(CommandResult::REASON_REQUEUE_FAILED, $this->now(), $e);
        }

        try {
            $receiver->reject($envelope);
        } catch (Throwable $e) {
            return CommandResult::failed(CommandResult::REASON_REMOVE_FAILED_AFTER_REQUEUE, $this->now(), $e);
        }

        return CommandResult::done(CommandResult::OUTCOME_REQUEUED, $this->now());
    }

    private function discard(ListableReceiverInterface $receiver, string $messageId): CommandResult
    {
        try {
            $envelope = $receiver->find($messageId);
        } catch (Throwable $e) {
            return CommandResult::failed(CommandResult::REASON_INTERNAL_ERROR, $this->now(), $e);
        }

        if (!$envelope instanceof Envelope) {
            return CommandResult::failed(CommandResult::REASON_NOT_FOUND, $this->now());
        }

        try {
            $receiver->reject($envelope);
        } catch (Throwable $e) {
            return CommandResult::failed(CommandResult::REASON_REMOVE_FAILED, $this->now(), $e);
        }

        return CommandResult::done(CommandResult::OUTCOME_REMOVED, $this->now());
    }

    private function isExpired(string $expiresAt): bool
    {
        try {
            $expires = new DateTimeImmutable($expiresAt);
        } catch (Exception) {
            return true;
        }

        return $expires->getTimestamp() <= $this->now()->getTimestamp() - self::CLOCK_SKEW;
    }

    private function listableReceiver(string $name): ?ListableReceiverInterface
    {
        $receiver = $this->service($name);

        return $receiver instanceof ListableReceiverInterface ? $receiver : null;
    }

    private function sender(string $name): ?SenderInterface
    {
        $sender = $this->service($name);

        return $sender instanceof SenderInterface ? $sender : null;
    }

    private function service(string $name): mixed
    {
        if ($name === '' || !$this->receiverLocator instanceof ContainerInterface) {
            return null;
        }

        try {
            return $this->receiverLocator->has($name) ? $this->receiverLocator->get($name) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function recall(string $id): mixed
    {
        $key = self::CACHE_PREFIX . hash('sha256', $id);

        if ($this->cache instanceof CacheItemPoolInterface) {
            try {
                $item = $this->cache->getItem($key);
                if ($item->isHit()) {
                    return $item->get();
                }
            } catch (Throwable $e) {
                $this->logger?->warning('Beacon remote command cache unavailable ({class}): using the in-process list.', ['class' => $e::class]);
            }
        }

        $entry = $this->memory[$key] ?? null;

        return $entry !== null && $entry['expires'] > $this->now()->getTimestamp() ? $entry['value'] : null;
    }

    private function remember(string $id, mixed $value): void
    {
        $key = self::CACHE_PREFIX . hash('sha256', $id);

        if (count($this->memory) >= self::MEMORY_MAX) {
            // @igor-ignore - Bounded replay guard (command ids only, no request data), kept for 24 h by design.
            $this->memory = [];
        }
        // @igor-ignore - Bounded replay guard (command ids only, no request data), kept for 24 h by design.
        $this->memory[$key] = ['value' => $value, 'expires' => $this->now()->getTimestamp() + self::EXECUTED_TTL];

        if (!$this->cache instanceof CacheItemPoolInterface) {
            return;
        }

        try {
            $item = $this->cache->getItem($key);
            $item->set($value);
            $item->expiresAfter(self::EXECUTED_TTL);
            $this->cache->save($item);
        } catch (Throwable $e) {
            $this->logger?->warning('Beacon remote command cache unavailable ({class}): using the in-process list.', ['class' => $e::class]);
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
