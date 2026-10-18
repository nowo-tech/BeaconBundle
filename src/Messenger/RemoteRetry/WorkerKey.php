<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use InvalidArgumentException;
use SensitiveParameter;

use function hash_hkdf;
use function preg_match;
use function trim;

/**
 * Beacon worker key (`bwk_` + base64url(32 random bytes)) and its HKDF-SHA256 subkeys.
 *
 * - `auth` = HKDF-SHA256(ikm = key, length 32, info `beacon-worker-auth-v1`, salt empty): Beacon stores
 *   `sha256(auth)`; the client authenticates with the plain key (`Authorization: Bearer bwk_…`).
 * - `sign` = HKDF-SHA256(ikm = key, length 32, info `beacon-command-sign-v1`, salt empty): HMAC-SHA256
 *   key of command signatures.
 *
 * The input keying material is the full key string exactly as configured (including the `bwk_`
 * prefix, surrounding whitespace trimmed). The key never appears in `var_dump()` / exceptions / logs.
 */
final class WorkerKey
{
    public const PREFIX = 'bwk_';

    public const INFO_AUTH = 'beacon-worker-auth-v1';

    public const INFO_SIGN = 'beacon-command-sign-v1';

    public const SUBKEY_LENGTH = 32;

    /** `bwk_` + base64url (no padding) of at least 32 bytes. */
    private const PATTERN = '/^bwk_[A-Za-z0-9_-]{43,}$/';

    private readonly string $key;

    /**
     * @throws InvalidArgumentException when the value is not a `bwk_…` key
     */
    public function __construct(#[SensitiveParameter] string $key)
    {
        $key = trim($key);
        if (!self::isValid($key)) {
            throw new InvalidArgumentException('Invalid Beacon worker key: expected "bwk_" followed by base64url characters (copy it from Beacon → Settings → Alerts → Remote Messenger commands).');
        }

        $this->key = $key;
    }

    /**
     * Whether `$key` has the `bwk_…` shape (trimmed).
     */
    public static function isValid(#[SensitiveParameter] string $key): bool
    {
        return preg_match(self::PATTERN, trim($key)) === 1;
    }

    /**
     * Value of the `Authorization` header (`Bearer bwk_…`).
     */
    public function authorizationHeader(): string
    {
        return 'Bearer ' . $this->key;
    }

    /**
     * Raw 32-byte authentication subkey (Beacon side stores its SHA-256).
     */
    public function authKey(): string
    {
        return hash_hkdf('sha256', $this->key, self::SUBKEY_LENGTH, self::INFO_AUTH);
    }

    /**
     * Raw 32-byte HMAC-SHA256 key for command signatures.
     */
    public function signingKey(): string
    {
        return hash_hkdf('sha256', $this->key, self::SUBKEY_LENGTH, self::INFO_SIGN);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => self::PREFIX . '***'];
    }
}
