<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\SelfTest;

use Exception;

/**
 * Deliberate failure raised by the Beacon self-test (`nowo:beacon:self-test` / optional HTTP endpoint).
 *
 * Extends {@see Exception} directly (not RuntimeException) so common `ignore_exceptions` entries
 * such as RuntimeException do not silence the self-test.
 */
final class BeaconSelfTestException extends Exception
{
}
