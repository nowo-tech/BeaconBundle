<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Support;

use Stringable;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function preg_match;
use function str_contains;
use function strtolower;

/**
 * Redacts secret-like keys and credential-shaped values for safe Beacon extras.
 */
final class SensitiveValueRedactor
{
    public const FILTERED = '[Filtered]';

    private const SENSITIVE_KEY = '/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|access[_-]?key|auth|credential|bearer|dsn|connection[_-]?string|salt|nonce)/i';

    /**
     * Whether `$key` looks sensitive (password, token, DSN, …).
     */
    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1;
    }

    /**
     * Redact a map of named values (console args/options, Monolog context, …).
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function redactMap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $name = (string) $key;
            if (self::isSensitiveKey($name)) {
                $out[$name] = self::FILTERED;

                continue;
            }
            $out[$name] = self::redactValue($value);
        }

        return $out;
    }

    /**
     * Redact a single value (scalars kept; nested arrays walked; objects summarized).
     */
    public static function redactValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        if (is_array($value)) {
            return self::redactMap($value);
        }

        if ($value instanceof Stringable) {
            return self::redactString((string) $value);
        }

        if (is_object($value)) {
            return $value::class;
        }

        return self::FILTERED;
    }

    /**
     * Hide credential-shaped strings (URI userinfo, long tokens).
     */
    private static function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // scheme://user:pass@host → scheme://[Filtered]@host
        if (preg_match('#^([a-z][a-z0-9+.-]*://)([^/@]+)@#i', $value) === 1) {
            return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)([^/@]+)@#i', '$1' . self::FILTERED . '@', $value);
        }

        $lower = strtolower($value);
        if (str_contains($lower, '-----begin') && str_contains($lower, 'private key')) {
            return self::FILTERED;
        }

        return $value;
    }
}
