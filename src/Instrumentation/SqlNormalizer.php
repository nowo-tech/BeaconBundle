<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use function is_string;
use function mb_substr;
use function preg_replace;

/**
 * Normalizes SQL text for span/breadcrumb descriptions (truncate + scrub string and numeric literals).
 */
final class SqlNormalizer
{
    public const MAX_SQL_LENGTH = 200;

    /**
     * Literal patterns scrubbed in order: single-quoted strings, double-quoted strings, then
     * standalone numbers / hex literals (not digits inside identifiers like `t0_` or `$1` params).
     *
     * @var list<non-empty-string>
     */
    private const LITERAL_PATTERNS = [
        "/'([^'\\\\]|\\\\.)*'/",
        '/"([^"\\\\]|\\\\.)*"/',
        '/(?<![\w.$])(?:0x[0-9a-f]+|\d+(?:\.\d+)?(?:e[+-]?\d+)?)\b/i',
    ];

    /**
     * @var list<string>
     */
    private const LITERAL_REPLACEMENTS = ["'?'", '"?"', '?'];

    /**
     * Collapse whitespace, scrub quoted and numeric literals, and truncate SQL for safe descriptions.
     */
    public static function normalize(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($sql));
        $sql       = is_string($collapsed) ? $collapsed : trim($sql);
        $scrubbed  = preg_replace(self::LITERAL_PATTERNS, self::LITERAL_REPLACEMENTS, $sql);
        $sql       = is_string($scrubbed) ? $scrubbed : $sql;

        return mb_substr($sql, 0, self::MAX_SQL_LENGTH);
    }
}
