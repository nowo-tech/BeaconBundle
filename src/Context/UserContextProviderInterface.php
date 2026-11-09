<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Context;

use Nowo\Beacon\Context\UserContextProviderInterface as CoreUserContextProviderInterface;

/**
 * Supplies a minimal authenticated-user snapshot for Beacon events.
 *
 * Extends beacon-php's {@see CoreUserContextProviderInterface}, so the core builder reads it directly.
 */
interface UserContextProviderInterface extends CoreUserContextProviderInterface
{
    /**
     * Build a minimal user snapshot for the `user` envelope field.
     *
     * @return array{id?: string, username?: string, email?: string}|null
     */
    public function getUserContext(): ?array;
}
