<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use function is_string;
use function mb_substr;
use function preg_replace;

/**
 * Normalizes SQL text for span/breadcrumb descriptions (truncate + scrub literals).
 */
final class SqlNormalizer
{
    public const MAX_SQL_LENGTH = 200;

    /**
     * Collapse whitespace, scrub quoted literals, and truncate SQL for safe descriptions.
     */
    public static function normalize(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($sql));
        $sql       = is_string($collapsed) ? $collapsed : trim($sql);
        $scrubbed  = preg_replace("/'([^'\\\\]|\\\\.)*'/", "'?'", $sql);
        $sql       = is_string($scrubbed) ? $scrubbed : $sql;

        return mb_substr($sql, 0, self::MAX_SQL_LENGTH);
    }
}
