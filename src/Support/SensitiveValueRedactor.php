<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Support;

use Stringable;

use function array_keys;
use function array_values;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function urldecode;

/**
 * Redacts secret-like keys and credential-shaped values for safe Beacon extras.
 */
final class SensitiveValueRedactor
{
    public const FILTERED = '[Filtered]';

    /**
     * Sensitive key names. Long words match anywhere (`db_password`, `X-Api-Key`, `session_id`); short ones (`pass`,
     * `pwd`, `pin`, `otp`, `cvv`, `card`) only as a whole token between non-alphanumerics (`db_pass`, `card_number`),
     * so `passenger`, `shipping` or `discard` are kept.
     */
    private const SENSITIVE_KEY = '/(?:password|passwd|passphrase|secret|token|api[_-]?key|private|access[_-]?key|auth|credential|bearer|dsn|connection[_-]?string|salt|nonce|cookie|session|signature|iban)|(?<![a-z0-9])(?:pass|pwd|pin|otp|cvv|cvc|card)(?![a-z0-9])/i';

    /** Secret-looking names in free text (see {@see SECRET_PATTERNS}). */
    private const SECRET_NAME = '(?:(?:passw(?:or)?d|passphrase|secret|token|api[_-]?key|access[_-]?key|private[_-]?key)[A-Za-z0-9_-]*|(?:pass|pwd|pin|otp|cvv|cvc))';

    /**
     * Credential-shaped substrings replaced inside free text (exception and log messages, context strings), the rest
     * of the text kept: PEM private keys, URL passwords, JWTs, bearer / basic tokens, provider keys (Stripe, AWS,
     * GitHub, Slack, Google, LLM APIs), Beacon worker keys and `password=…` / `"api_key": "…"` / `'pass' => '…'` pairs.
     *
     * @var array<string, string> pattern => replacement
     */
    private const SECRET_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s' => self::FILTERED,
        // scheme://user:password@host — the password may contain `/` or `@` (up to the last `@` of the word); a port
        // followed by a path (`host:8080/…`) is not a password.
        '#\b([a-z][a-z0-9+.-]*://[^\s:/@]+):(?!\d{1,5}/)[^\s]+@#i' => '$1:' . self::FILTERED . '@',
        '/\beyJ[\w-]{8,}\.eyJ[\w-]{8,}\.[\w-]{8,}/'                => self::FILTERED,
        '/\b(Bearer|Basic)\s+[\w.~+\/=-]{8,}/i'                    => '$1 ' . self::FILTERED,
        '/\b(?:sk|rk|pk)_(?:live|test)_\w{10,}/'                   => self::FILTERED,
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/'                          => self::FILTERED,
        '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/'                         => self::FILTERED,
        '/\bgithub_pat_\w{30,}/'                                   => self::FILTERED,
        '/\bxox[abposr]-[\w-]{10,}/'                               => self::FILTERED,
        '/\bAIza[\w-]{35}\b/'                                      => self::FILTERED,
        '/\bsk-(?:ant-)?[\w-]{20,}/'                               => self::FILTERED,
        // Beacon worker keys (remote Messenger commands).
        '/(?<![A-Za-z0-9])bwk_[A-Za-z0-9_-]{20,}/' => self::FILTERED,
        // name=value / name: value / 'name' => 'value' / "name": "value with spaces". The name may be part of a
        // longer identifier (`DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `beacon_secret`, `smtp_pass`); short names
        // (`pass`, `pwd`, `pin`, `otp`, `cvv`) must end there. Quoted values are filtered whole (quotes kept);
        // unquoted values run to whitespace, a quote, `&`, `,`, `}` or `]`.
        '/(?<![A-Za-z0-9])(' . self::SECRET_NAME . '["\']?\s*(?:=>|[:=])\s*")[^"]*"/i'                        => '$1' . self::FILTERED . '"',
        '/(?<![A-Za-z0-9])(' . self::SECRET_NAME . '["\']?\s*(?:=>|[:=])\s*\')[^\']*\'/i'                     => '$1' . self::FILTERED . "'",
        '/(?<![A-Za-z0-9])(' . self::SECRET_NAME . '["\']?\s*(?:=>|[:=])\s*)(?!\[Filtered\])[^\s"\'&,}\]]+/i' => '$1' . self::FILTERED,
    ];

    /**
     * Extra query parameter names that carry one-time credentials in URLs only: Symfony login links
     * (`hash`), signed URLs (`_hash`, `signature`, `X-Amz-Signature`, `sig`), OAuth callbacks (`code`), API keys
     * (`key`), reset links (`reset`) and session ids (`sid`). Not part of {@see SENSITIVE_KEY}: a `code`, `key` or
     * `hash` key in Monolog context or console input is usually not a secret.
     */
    private const SENSITIVE_URL_PARAMETER = '/^(?:_?hash|sig|code|key|reset|sid)$|signature|token/i';

    /**
     * Replace credential-shaped substrings in free text (an exception or log message), keeping the rest.
     */
    public static function redactMessage(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        $message = preg_replace(array_keys(self::SECRET_PATTERNS), array_values(self::SECRET_PATTERNS), $message) ?? $message;

        // Payment card numbers: Visa / Mastercard / Amex / Discover prefixes, 15–19 digits, valid Luhn checksum.
        return preg_replace_callback(
            '/(?<![\d-])(?:4|5[1-5]|2[2-7]|3[47]|6)(?:[ -]?\d){14,18}(?![\d-])/',
            static fn (array $match): string => self::passesLuhn((string) preg_replace('/\D/', '', $match[0])) ? self::FILTERED : $match[0],
            $message,
        ) ?? $message;
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum    = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

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
     * Redact a raw query string pair by pair (`token=abc&page=2` → `token=[Filtered]&page=2`).
     *
     * URL credential parameters (`hash`, `_hash`, `signature`, `sig`, `code`, `token`) are filtered; every
     * other decoded key/value goes through the same rules as {@see redactMap()}; pairs that do not
     * need redaction are kept byte for byte (order, encoding and array syntax preserved).
     */
    public static function redactQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return $queryString;
        }

        $pairs = explode('&', $queryString);
        foreach ($pairs as $index => $pair) {
            $separator = strpos($pair, '=');
            if ($separator === false) {
                continue;
            }

            $key   = urldecode(substr($pair, 0, $separator));
            $value = urldecode(substr($pair, $separator + 1));

            if ($value !== '' && self::isSensitiveUrlParameter($key)) {
                $pairs[$index] = substr($pair, 0, $separator + 1) . self::FILTERED;

                continue;
            }

            $redacted = self::redactMap([$key => $value])[$key];
            if ($redacted !== $value) {
                $pairs[$index] = substr($pair, 0, $separator + 1) . (is_string($redacted) && $redacted !== self::FILTERED ? rawurlencode($redacted) : self::FILTERED);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Whether a query parameter name (array syntax `name[...]` reduced to `name`) carries a URL credential.
     */
    private static function isSensitiveUrlParameter(string $key): bool
    {
        return preg_match(self::SENSITIVE_URL_PARAMETER, (string) preg_replace('/\[.*$/s', '', trim($key))) === 1;
    }

    /**
     * Redact credentials in a URL: userinfo (`user:pass@`) and secret-like query parameters.
     */
    public static function redactUrl(string $url): string
    {
        $url = self::redactString($url);

        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragment      = '';
        $fragmentStart = strpos($url, '#', $queryStart);
        if ($fragmentStart !== false) {
            $fragment = substr($url, $fragmentStart);
            $url      = substr($url, 0, $fragmentStart);
        }

        return substr($url, 0, $queryStart + 1) . self::redactQueryString(substr($url, $queryStart + 1)) . $fragment;
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

        return self::redactMessage($value);
    }
}
