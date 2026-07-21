<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Composer\InstalledVersions;
use Throwable;

use function class_exists;
use function is_string;

/**
 * Resolves the outbound User-Agent for Envelope HTTP requests.
 *
 * Prefers the installed Composer package version; falls back to a stable major.minor label.
 */
final class ClientUserAgent
{
    private const PACKAGE = 'nowo-tech/beacon-bundle';

    private const FALLBACK = 'beacon-bundle/1.6';

    /**
     * User-Agent string, e.g. `beacon-bundle/1.6.0` or `beacon-bundle/dev-main`.
     */
    public static function resolve(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return self::FALLBACK;
        }

        try {
            if (!InstalledVersions::isInstalled(self::PACKAGE)) {
                return self::FALLBACK;
            }

            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
            if (is_string($pretty) && $pretty !== '') {
                return 'beacon-bundle/' . ltrim($pretty, 'v');
            }
        } catch (Throwable) {
            return self::FALLBACK;
        }

        return self::FALLBACK;
    }
}
