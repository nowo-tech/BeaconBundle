<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

/**
 * Parsed `200` body of the claim call.
 */
final class ClaimResponse
{
    /**
     * @param list<RemoteCommand> $commands Usable commands (entries without a usable id / claim token are dropped)
     * @param int|null $pollAfter Seconds Beacon asks the worker to wait before the next claim
     */
    public function __construct(
        public readonly array $commands,
        public readonly ?int $pollAfter,
    ) {
    }
}
