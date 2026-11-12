<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Credentials;

/**
 * Writable {@see BeaconCredentialsProviderInterface}: needed by `nowo:beacon:credentials:rotate`, which writes the new
 * values, keeps the replaced ones as "previous" until their expiry and restores the old record when the self-test
 * fails.
 */
interface BeaconCredentialsStoreInterface extends BeaconCredentialsProviderInterface
{
    /**
     * Replace the stored record (a null value removes that value).
     */
    public function save(BeaconCredentials $credentials): void;
}
