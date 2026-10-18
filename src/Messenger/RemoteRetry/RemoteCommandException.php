<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use RuntimeException;

/**
 * A claim / result call to Beacon did not succeed. Messages carry the HTTP status or the transport
 * error class only — never the worker key, claim tokens or response bodies.
 */
final class RemoteCommandException extends RuntimeException
{
    /** 401 / 403 / 404 on claim, 401 / 403 on result: wrong key, revoked key, unknown project or an older Beacon. */
    public const KIND_AUTH = 'auth';

    /** 429 / 503: back off as told by `Retry-After`. */
    public const KIND_RATE_LIMITED = 'rate_limited';

    /** Network error, timeout, other 5xx / unexpected status or an unreadable body: exponential backoff. */
    public const KIND_UNAVAILABLE = 'unavailable';

    /** Result call refused for good (404 unknown command / claim token, 409 different final result, other 4xx). */
    public const KIND_REJECTED = 'rejected';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
