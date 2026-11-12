<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Credentials;

/**
 * Port: where the host keeps Beacon credentials besides `nowo_beacon.dsn` (a database row, a vault, instance settings).
 *
 * Set `nowo_beacon.credentials_provider` to the id of a service implementing it. The bundle calls {@see current()} at
 * runtime (never at container compile time), caches the answer for `credentials_cache_ttl` seconds and merges it over
 * the configuration: a value returned here wins, a null value falls back to the configured one.
 *
 * Implementations may throw (database down, decryption failure): the bundle then uses the configured values, logs one
 * warning and never lets the error reach the reporting path. They must not keep request or user data.
 */
interface BeaconCredentialsProviderInterface
{
    /**
     * Stored credentials, or null when nothing is stored (the configured values apply).
     */
    public function current(): ?BeaconCredentials;
}
