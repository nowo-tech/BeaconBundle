<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Support;

use Nowo\Beacon\Scrubber\SensitiveValueRedactor as CoreSensitiveValueRedactor;

/**
 * Redacts secret-like keys and credential-shaped values for safe Beacon extras.
 *
 * Thin shim kept for BC: every method delegates to nowo-tech/beacon-php's
 * {@see CoreSensitiveValueRedactor}, so the bundle and the core client share one set of rules, including the
 * fail-closed behaviour on PCRE errors ({@see REDACTION_FAILED}).
 */
final class SensitiveValueRedactor
{
    public const FILTERED = CoreSensitiveValueRedactor::FILTERED;

    /** Returned instead of the original text when a PCRE error makes redaction fail (fail closed). */
    public const REDACTION_FAILED = CoreSensitiveValueRedactor::REDACTION_FAILED;

    /** Longest free text redacted, in bytes; the rest is dropped (never returned unredacted). */
    public const MAX_MESSAGE_LENGTH = CoreSensitiveValueRedactor::MAX_MESSAGE_LENGTH;

    /** Appended to a message cut at {@see MAX_MESSAGE_LENGTH}. */
    public const TRUNCATED = CoreSensitiveValueRedactor::TRUNCATED;

    /**
     * Replace credential-shaped substrings in free text (an exception or log message), keeping the rest.
     */
    public static function redactMessage(string $message): string
    {
        return CoreSensitiveValueRedactor::redactMessage($message);
    }

    /**
     * Whether `$key` looks sensitive (password, token, DSN, …).
     */
    public static function isSensitiveKey(string $key): bool
    {
        return CoreSensitiveValueRedactor::isSensitiveKey($key);
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
        return CoreSensitiveValueRedactor::redactMap($values);
    }

    /**
     * Redact a raw query string pair by pair (`token=abc&page=2` → `token=[Filtered]&page=2`).
     */
    public static function redactQueryString(string $queryString): string
    {
        return CoreSensitiveValueRedactor::redactQueryString($queryString);
    }

    /**
     * Redact credentials in a URL: userinfo (`user:pass@`) and secret-like query parameters.
     */
    public static function redactUrl(string $url): string
    {
        return CoreSensitiveValueRedactor::redactUrl($url);
    }

    /**
     * Redact a single value (scalars kept; nested arrays walked; objects summarized).
     */
    public static function redactValue(mixed $value): mixed
    {
        return CoreSensitiveValueRedactor::redactValue($value);
    }
}
