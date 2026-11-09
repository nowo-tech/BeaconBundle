<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use InvalidArgumentException;
use Nowo\Beacon\Dsn\InvalidDsnException;

/**
 * Thrown when a Beacon DSN string cannot be parsed.
 *
 * Extends beacon-php's {@see InvalidDsnException} (itself an {@see InvalidArgumentException}).
 */
final class InvalidBeaconDsnException extends InvalidDsnException
{
}
