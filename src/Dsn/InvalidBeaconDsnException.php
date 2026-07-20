<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use InvalidArgumentException;

/**
 * Thrown when a Beacon DSN string cannot be parsed.
 */
final class InvalidBeaconDsnException extends InvalidArgumentException
{
}
