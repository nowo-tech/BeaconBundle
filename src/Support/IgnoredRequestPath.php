<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Support;

/**
 * Matches request path info against configured ignore path prefixes.
 *
 * Defaults mirror the infrastructure paths other Nowo / Symfony Beacon pieces
 * already treat as non-app traffic (security firewall, PWA deny cache,
 * site-backup exclusions, setup redirect skip list, auto HTTP transactions),
 * plus Chrome DevTools Appspecific probes.
 *
 * Trailing slashes are normalized so probes that end with `/` still match.
 */
final class IgnoredRequestPath
{
    /**
     * Default noise paths skipped by the HTTP exception listener and auto HTTP transactions.
     *
     * Keep in sync with typical Symfony Beacon host exclusions:
     * `/_profiler`, `/_wdt`, `/build`, `/assets`, `/health` (+ Chrome DevTools).
     *
     * @var list<string>
     */
    public const DEFAULTS = [
        '/_profiler',
        '/_wdt',
        '/build',
        '/assets',
        '/health',
        '/.well-known/appspecific/com.chrome.devtools.json',
    ];

    /**
     * @param list<string> $ignorePaths Exact paths or prefixes (no trailing slash required)
     */
    public static function matches(string $pathInfo, array $ignorePaths): bool
    {
        if ($ignorePaths === []) {
            return false;
        }

        $path = self::normalize($pathInfo);

        foreach ($ignorePaths as $ignored) {
            if ($ignored === '') {
                continue;
            }

            $prefix = self::normalize($ignored);
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
