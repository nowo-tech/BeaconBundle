<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

/**
 * Clients that can send Messenger queue snapshots (`queue_snapshot` Envelope items).
 *
 * Kept separate from {@see BeaconClientInterface} so custom client implementations stay compatible.
 */
interface QueueSnapshotCapableInterface
{
    /**
     * Send message counts per Messenger transport.
     *
     * @param list<array{name: string, count: int|null, failure: bool}> $transports
     *
     * @return string|null Event id when sent (null when disabled)
     */
    public function captureQueueSnapshot(array $transports): ?string;
}
