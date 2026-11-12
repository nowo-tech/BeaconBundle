<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\CredentialsKernel;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/report/{iteration}`: captures one message.
 */
final readonly class ReportController
{
    public function __construct(
        private BeaconClientInterface $client,
    ) {
    }

    public function __invoke(string $iteration): Response
    {
        $this->client->captureMessage('report ' . $iteration, 'info');

        return new Response('ok');
    }
}
