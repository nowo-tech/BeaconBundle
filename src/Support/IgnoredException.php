<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Support;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

use function is_string;

/**
 * Single ignore rule for every automatic capture path (HTTP / console / Messenger listeners and
 * the Monolog handler), so the listeners and the handler cannot drift apart.
 *
 * - `$ignoreExceptions`: FQCN list (`nowo_beacon.ignore_exceptions`); `instanceof` match, so
 *   parent classes and interfaces work. Non-string or empty entries are skipped.
 * - `$ignoreHttpClientErrors`: also skip any {@see HttpExceptionInterface} whose status code is
 *   below 500 (404, 403, 405, 400, …).
 */
final class IgnoredException
{
    /**
     * @param list<mixed> $ignoreExceptions
     */
    public static function matches(Throwable $throwable, array $ignoreExceptions, bool $ignoreHttpClientErrors = false): bool
    {
        if ($ignoreHttpClientErrors && self::isHttpClientError($throwable)) {
            return true;
        }

        foreach ($ignoreExceptions as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            if ($throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$throwable` is an HTTP exception with a 4xx (or lower) status code.
     */
    public static function isHttpClientError(Throwable $throwable): bool
    {
        return $throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500;
    }
}
