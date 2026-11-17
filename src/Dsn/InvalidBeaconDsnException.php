<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use InvalidArgumentException;
use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\Exception\BeaconExceptionInterface;

/**
 * Thrown when a Beacon DSN string cannot be parsed.
 *
 * Since 1.27.0 a standalone {@see InvalidArgumentException} implementing beacon-php's {@see BeaconExceptionInterface}
 * (beacon-php 0.3 made {@see InvalidDsnException} final). {@see BeaconDsnParser} throws it with the same message as the
 * core {@see InvalidDsnException}, which it carries as `previous`. Catch this class, `BeaconExceptionInterface` or
 * `InvalidArgumentException`; it is no longer an `InvalidDsnException`.
 */
final class InvalidBeaconDsnException extends InvalidArgumentException implements BeaconExceptionInterface
{
}
