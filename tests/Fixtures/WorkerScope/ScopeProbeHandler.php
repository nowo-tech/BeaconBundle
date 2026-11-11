<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\WorkerScope;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use RuntimeException;

/**
 * Adds a breadcrumb and scope tags for the message's user, then fails on demand (the bundle's failure listener
 * captures the error with the scope of this message).
 */
final readonly class ScopeProbeHandler
{
    public function __construct(
        private BeaconClientInterface $client,
    ) {
    }

    public function __invoke(ScopeProbeMessage $message): void
    {
        $this->client->addBreadcrumb('msg-' . $message->user . '-' . $message->iteration, 'probe');
        $this->client->setTags(['tenant' => $message->user, 'msg_only' => (string) $message->iteration]);

        if ($message->fail) {
            throw new RuntimeException('Probe failure for ' . $message->user);
        }
    }
}
