<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Credentials;

/**
 * Obviously fake DSNs and worker keys of the credentials tests (placeholders, never real secrets).
 */
final class CredentialsFixtures
{
    /** Configured DSN (".env"). */
    public const CONFIG_DSN = 'https://pub-env:fixture-secret-env@beacon.example.invalid/1';

    /** DSN stored by the provider: same project, rotated secret. */
    public const DB_DSN = 'https://pub-db:fixture-secret-db@beacon.example.invalid/1';

    /** Next DSN of the same project (after a rotation). */
    public const NEXT_DSN = 'https://pub-next:fixture-secret-next@beacon.example.invalid/1';

    /** DSN of another project. */
    public const OTHER_PROJECT_DSN = 'https://pub-two:fixture-secret-two@beacon.example.invalid/2';

    public const CONFIG_KEY = 'bwk_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    public const DB_KEY = 'bwk_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    public const NEXT_KEY = 'bwk_CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

    /**
     * Every secret part above, for "never logged / never shown" assertions.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        return [
            'fixture-secret-env', 'fixture-secret-db', 'fixture-secret-next', 'fixture-secret-two', 'fixture-db-password',
            self::CONFIG_KEY, self::DB_KEY, self::NEXT_KEY,
        ];
    }
}
