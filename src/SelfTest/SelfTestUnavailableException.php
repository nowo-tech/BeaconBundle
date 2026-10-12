<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\SelfTest;

use RuntimeException;

/**
 * The self-test cannot run: reporting is disabled, the DSN is empty / invalid, or the
 * listener / Messenger bus the requested kind relies on is not available.
 */
final class SelfTestUnavailableException extends RuntimeException
{
}
