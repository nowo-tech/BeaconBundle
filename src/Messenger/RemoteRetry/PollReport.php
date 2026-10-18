<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

/**
 * What one poll did (for `nowo:beacon:messenger-commands` and tests). Ids and codes only.
 */
final class PollReport
{
    /** Claim succeeded (with or without commands). */
    public const STATUS_OK = 'ok';

    /** DSN / worker key missing or invalid: nothing is polled. */
    public const STATUS_DISABLED = 'disabled';

    /** Polling paused for an hour after 401 / 403 / 404. */
    public const STATUS_PAUSED = 'paused';

    /** Beacon answered 401 / 403 / 404 now (polling paused for an hour). */
    public const STATUS_AUTH_ERROR = 'auth_error';

    /** Beacon answered 429 / 503. */
    public const STATUS_RATE_LIMITED = 'rate_limited';

    /** Network error / 5xx / unreadable response (exponential backoff). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param list<array{id: string, action: string, failure_transport: string, message_id: string, result: string, reported: bool}> $commands
     * @param float $nextPollIn Seconds until the next poll is due
     */
    public function __construct(
        public readonly string $status,
        public readonly float $nextPollIn,
        public readonly array $commands = [],
        public readonly ?string $detail = null,
    ) {
    }
}
