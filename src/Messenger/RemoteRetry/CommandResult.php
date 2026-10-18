<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

use function is_array;
use function is_string;
use function mb_substr;

/**
 * Outcome of one command, posted to `POST /api/{project}/messenger/commands/{id}/result`.
 *
 * Carries only codes and, optionally, the exception class (≤ 255) — never exception messages,
 * never message bodies.
 */
final class CommandResult
{
    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const OUTCOME_REQUEUED = 'requeued';

    public const OUTCOME_REMOVED = 'removed';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_TRANSPORT_NOT_ALLOWED = 'transport_not_allowed';

    public const REASON_TRANSPORT_NOT_LISTABLE = 'transport_not_listable';

    public const REASON_ACTION_NOT_ALLOWED = 'action_not_allowed';

    public const REASON_SIGNATURE_INVALID = 'signature_invalid';

    public const REASON_EXPIRED = 'expired';

    public const REASON_REQUEUE_FAILED = 'requeue_failed';

    public const REASON_REMOVE_FAILED = 'remove_failed';

    public const REASON_REMOVE_FAILED_AFTER_REQUEUE = 'remove_failed_after_requeue';

    public const REASON_INTERNAL_ERROR = 'internal_error';

    /** Closed list of failure reason codes (Beacon spec 154). */
    public const REASONS = [
        self::REASON_NOT_FOUND,
        self::REASON_TRANSPORT_NOT_ALLOWED,
        self::REASON_TRANSPORT_NOT_LISTABLE,
        self::REASON_ACTION_NOT_ALLOWED,
        self::REASON_SIGNATURE_INVALID,
        self::REASON_EXPIRED,
        self::REASON_REQUEUE_FAILED,
        self::REASON_REMOVE_FAILED,
        self::REASON_REMOVE_FAILED_AFTER_REQUEUE,
        self::REASON_INTERNAL_ERROR,
    ];

    private const MAX_EXCEPTION_CLASS = 255;

    private function __construct(
        public readonly string $status,
        public readonly ?string $outcome,
        public readonly ?string $reasonCode,
        public readonly ?string $exceptionClass,
        public readonly string $executedAt,
    ) {
    }

    public static function done(string $outcome, DateTimeImmutable $at): self
    {
        return new self(self::STATUS_DONE, $outcome, null, null, self::utc($at));
    }

    public static function failed(string $reasonCode, DateTimeImmutable $at, ?Throwable $exception = null): self
    {
        return new self(
            self::STATUS_FAILED,
            null,
            $reasonCode,
            $exception instanceof Throwable ? mb_substr($exception::class, 0, self::MAX_EXCEPTION_CLASS) : null,
            self::utc($at),
        );
    }

    /**
     * Rebuild a result stored in the executed-ids cache (null when the value is not one).
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (!is_array($stored)) {
            return null;
        }

        $status     = $stored['status'] ?? null;
        $executedAt = $stored['executed_at'] ?? null;
        if (($status !== self::STATUS_DONE && $status !== self::STATUS_FAILED) || !is_string($executedAt)) {
            return null;
        }

        return new self(
            $status,
            self::nullableString($stored['outcome'] ?? null),
            self::nullableString($stored['reason_code'] ?? null),
            self::nullableString($stored['exception_class'] ?? null),
            $executedAt,
        );
    }

    /**
     * Value kept in the executed-ids cache.
     *
     * @return array{status: string, outcome: string|null, reason_code: string|null, exception_class: string|null, executed_at: string}
     */
    public function toStored(): array
    {
        return [
            'status'          => $this->status,
            'outcome'         => $this->outcome,
            'reason_code'     => $this->reasonCode,
            'exception_class' => $this->exceptionClass,
            'executed_at'     => $this->executedAt,
        ];
    }

    /**
     * JSON body of the result call.
     *
     * @return array{claim_token: string, status: string, outcome: string|null, reason_code: string|null, exception_class: string|null, executed_at: string, server_name: string}
     */
    public function toPayload(string $claimToken, string $serverName): array
    {
        return [
            'claim_token'     => $claimToken,
            'status'          => $this->status,
            'outcome'         => $this->outcome,
            'reason_code'     => $this->reasonCode,
            'exception_class' => $this->exceptionClass,
            'executed_at'     => $this->executedAt,
            'server_name'     => mb_substr($serverName, 0, 255),
        ];
    }

    /**
     * Short human summary for logs / console (codes only).
     */
    public function summary(): string
    {
        if ($this->status === self::STATUS_DONE) {
            return 'done (' . $this->outcome . ')';
        }

        return 'failed (' . $this->reasonCode . ($this->exceptionClass !== null ? ', ' . $this->exceptionClass : '') . ')';
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
