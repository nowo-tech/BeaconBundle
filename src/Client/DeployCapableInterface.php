<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

/**
 * Clients that can send deploy markers (`deploy` Envelope items).
 *
 * Kept separate from {@see BeaconClientInterface} so custom client implementations stay compatible.
 */
interface DeployCapableInterface
{
    /**
     * @param array{release: string, environment?: string|null, commit?: string|null, url?: string|null, name?: string|null, deployed_at?: float|null} $deploy
     *
     * @return string|null Event id when sent (null when disabled)
     */
    public function captureDeploy(array $deploy): ?string;
}
