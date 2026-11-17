<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Dsn\InvalidDsnException;
use SensitiveParameter;

/**
 * Parses a Beacon DSN into {@see BeaconDsn}.
 *
 * Since 1.27.0 the rules are beacon-php's {@see DsnParser} (the same accepted inputs and messages as before, written
 * from this class, plus the refusal of control characters in the keys); a core {@see InvalidDsnException} is rethrown
 * as {@see InvalidBeaconDsnException} with the same message and the core exception as `previous`.
 */
final class BeaconDsnParser
{
    /**
     * Parse and validate a Beacon DSN string.
     *
     * @throws InvalidBeaconDsnException
     */
    public function parse(#[SensitiveParameter] string $dsn): BeaconDsn
    {
        try {
            return BeaconDsn::fromCoreDsn((new DsnParser())->parse($dsn));
        } catch (InvalidDsnException $e) {
            throw new InvalidBeaconDsnException($e->getMessage(), 0, $e);
        }
    }
}
