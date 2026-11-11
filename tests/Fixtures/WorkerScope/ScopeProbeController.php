<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\WorkerScope;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/probe/{user}/{iteration}/{capture}`: adds a breadcrumb and a tenant tag for `$user`; captures a message when
 * `$capture` is "1", otherwise leaves the state behind for the next unit of work.
 */
final readonly class ScopeProbeController
{
    public function __construct(
        private BeaconClientInterface $client,
    ) {
    }

    public function __invoke(string $user, string $iteration, string $capture): Response
    {
        $this->client->addBreadcrumb('http-' . $user . '-' . $iteration, 'probe');
        $this->client->setTags(['tenant' => $user, 'http_only' => $iteration]);

        if ($capture === '1') {
            $this->client->captureMessage('http probe ' . $user);
        }

        return new Response('ok');
    }
}
