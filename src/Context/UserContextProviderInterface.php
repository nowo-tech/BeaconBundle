<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Context;

/**
 * Supplies a minimal authenticated-user snapshot for Beacon events.
 */
interface UserContextProviderInterface
{
    /**
     * @return array{id?: string, username?: string, email?: string}|null
     */
    public function getUserContext(): ?array;
}
