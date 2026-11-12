<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Credentials;

use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsProviderInterface;

/**
 * Read-only provider (the rotate command gets no store).
 */
final class ReadOnlyProvider implements BeaconCredentialsProviderInterface
{
    public function current(): ?BeaconCredentials
    {
        return null;
    }
}
