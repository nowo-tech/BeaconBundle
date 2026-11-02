<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Support;

use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use stdClass;

use function bin2hex;
use function ini_set;
use function json_encode;
use function random_bytes;
use function str_contains;
use function str_repeat;

final class SensitiveValueRedactorTest extends TestCase
{
    public function testRedactsSensitiveKeysAndCredentialUris(): void
    {
        $out = SensitiveValueRedactor::redactMap([
            'name'     => 'demo',
            'password' => 's3cret',
            'api_key'  => 'abc',
            'dsn'      => 'mysql://user:pass@db:3306/app',
            'url'      => 'mysql://user:pass@db:3306/app',
            'nested'   => ['token' => 'x', 'ok' => 1],
            'object'   => new stdClass(),
        ]);

        self::assertSame('demo', $out['name']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['password']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['api_key']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['dsn']);
        self::assertSame('mysql://' . SensitiveValueRedactor::FILTERED . '@db:3306/app', $out['url']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['nested']['token']);
        self::assertSame(1, $out['nested']['ok']);
        self::assertSame(stdClass::class, $out['object']);
    }

    public function testRedactQueryStringFiltersSecretKeysAndKeepsOthers(): void
    {
        self::assertSame('', SensitiveValueRedactor::redactQueryString(''));
        self::assertSame(
            'page=2&token=[Filtered]&api_key=[Filtered]&user%5Bpassword%5D=[Filtered]&flag&q=hello%20world',
            SensitiveValueRedactor::redactQueryString('page=2&token=abc123&api_key=k&user%5Bpassword%5D=pw&flag&q=hello%20world'),
        );
        self::assertSame(
            'next=' . rawurlencode('https://[Filtered]@db.test/x'),
            SensitiveValueRedactor::redactQueryString('next=' . rawurlencode('https://admin:pw@db.test/x')),
        );
        self::assertSame(
            'pem=[Filtered]',
            SensitiveValueRedactor::redactQueryString('pem=' . rawurlencode('-----BEGIN PRIVATE KEY-----')),
        );
    }

    public function testRedactUrlFiltersQueryAndUserinfoAndKeepsFragment(): void
    {
        self::assertSame('https://demo.test/path', SensitiveValueRedactor::redactUrl('https://demo.test/path'));
        self::assertSame(
            'https://demo.test/reset?token=[Filtered]&x=1#frag',
            SensitiveValueRedactor::redactUrl('https://demo.test/reset?token=s3cr3t&x=1#frag'),
        );
        self::assertSame(
            'https://[Filtered]@demo.test/?access_token=[Filtered]',
            SensitiveValueRedactor::redactUrl('https://user:pw@demo.test/?access_token=abc'),
        );
    }

    public function testRedactUrlFiltersLoginLinkAndSignedUrlParameters(): void
    {
        self::assertSame(
            'https://demo.test/login_check?user=jane%40example.com&expires=1760000000&hash=[Filtered]',
            SensitiveValueRedactor::redactUrl('https://demo.test/login_check?user=jane%40example.com&expires=1760000000&hash=Jm9hB2p0dE5kbz0~abc'),
        );
        self::assertSame(
            'https://demo.test/verify?id=7&_hash=[Filtered]&signature=[Filtered]&sig=[Filtered]&X-Amz-Signature=[Filtered]&expires=9',
            SensitiveValueRedactor::redactUrl('https://demo.test/verify?id=7&_hash=h1&signature=s1&sig=s2&X-Amz-Signature=s3&expires=9'),
        );
    }

    public function testRedactUrlFiltersOAuthCallbackCode(): void
    {
        self::assertSame(
            'https://demo.test/connect/check?code=[Filtered]&state=xyz&country_code=ES&zip_code=28001',
            SensitiveValueRedactor::redactUrl('https://demo.test/connect/check?code=4%2F0AfJohXk-secret&state=xyz&country_code=ES&zip_code=28001'),
        );
        self::assertSame('code=&page=1', SensitiveValueRedactor::redactQueryString('code=&page=1'));
        self::assertSame('code%5B0%5D=[Filtered]', SensitiveValueRedactor::redactQueryString('code%5B0%5D=x'));
    }

    public function testUrlOnlyParameterNamesAreNotFilteredInMaps(): void
    {
        $out = SensitiveValueRedactor::redactMap(['hash' => 'abc', 'code' => 'E42', 'sig' => 'v1', 'key' => 'k', 'reset' => 'r']);

        self::assertSame(['hash' => 'abc', 'code' => 'E42', 'sig' => 'v1', 'key' => 'k', 'reset' => 'r'], $out);
        // `signature` is a credential key everywhere since 1.19.2 (SEC-004 Appendix CH, L1).
        self::assertSame(['signature' => SensitiveValueRedactor::FILTERED], SensitiveValueRedactor::redactMap(['signature' => 'Jane']));
    }

    public function testRedactMessageReplacesCredentialShapedSubstrings(): void
    {
        self::assertSame('', SensitiveValueRedactor::redactMessage(''));
        self::assertSame('Undefined index: user_id', SensitiveValueRedactor::redactMessage('Undefined index: user_id'));
        self::assertSame(
            'Stripe said no to [Filtered] (Bearer [Filtered]) for password=[Filtered], db mysql://app:[Filtered]@db/app',
            SensitiveValueRedactor::redactMessage('Stripe said no to sk_live_abcdefghijklmnop (Bearer abcdefgh12345) for password=hunter2, db mysql://app:s3cr3t@db/app'),
        );
        self::assertSame(
            '[Filtered] [Filtered] [Filtered] [Filtered] [Filtered] [Filtered] [Filtered]',
            SensitiveValueRedactor::redactMessage('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N AKIAABCDEFGHIJKLMNOP ghp_abcdefghijklmnopqrstuvwxyz0123456789 github_pat_abcdefghijklmnopqrstuvwxyz0123 xoxb-1234567890-abc AIzaSyA1234567890abcdefghijklmnopqrstuv sk-ant-api03-abcdefghijklmnopqrstuvwxyz'),
        );
        self::assertSame('{"api_key": "[Filtered]"}', SensitiveValueRedactor::redactMessage('{"api_key": "abc123"}'));
    }

    public function testContextStringsGetTheMessagePatterns(): void
    {
        self::assertSame(['note' => 'sent Bearer [Filtered]'], SensitiveValueRedactor::redactMap(['note' => 'sent Bearer abcdefgh12345']));
    }

    /**
     * SEC-004 Appendix CH, L1: inputs that leaked before 1.19.2 (all values fake).
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function leakingMessages(): iterable
    {
        // Secret-shaped values are generated at runtime so no credential-looking literal is committed.
        $v = static fn (string $prefix = 'Fk'): string => $prefix . bin2hex(random_bytes(8));
        $a = $v();
        yield 'env-style DB_PASSWORD' => ['Failed to boot: DB_PASSWORD=' . $a . ' is wrong', [$a]];
        $a = $v();
        yield 'env-style AWS_SECRET_ACCESS_KEY' => ['AWS_SECRET_ACCESS_KEY=' . $a . ' rejected', [$a]];
        $a = $v();
        yield 'env-style STRIPE_API_KEY' => ['STRIPE_API_KEY=' . $a . ' invalid', [$a]];
        $a = $v();
        yield 'PHP array export' => ["Options were ['password' => '" . $a . "', 'user' => 'app']", [$a]];
        $a = $v('horse');
        $b = $v('battery');
        yield 'JSON pair with spaces' => ['Payload {"password": "correct ' . $a . ' ' . $b . '"}', [$a, $b]];
        $a = $v();
        yield 'password with ;' => ['login failed for password=Fa;' . $a, [$a]];
        $a = $v();
        yield 'passphrase' => ['passphrase=' . $a, [$a]];
        $a = $v();
        yield 'pass=' => ['smtp pass=' . $a . ' refused', [$a]];
        $a = $v();
        yield 'pwd / pin / otp / cvv' => ['pwd=' . $a . ' pin: 4321 otp=987654 cvv=123', [$a, '4321', '987654', '=123']];
        $a = $v();
        yield 'URL password with /' => ['Cannot connect to mysql://app:Fk/' . $a . '@db.internal:3306/app', [$a]];
        $a = $v();
        yield 'URL password with @' => ['Cannot connect to redis://default:Fk@' . $a . '@cache:6379', [$a]];
        $a = $v();
        yield 'Beacon DSN' => ['Bad DSN https://pubFAKEkey:' . $a . '@errors.example/1', [$a]];
        $a = 'bwk_' . bin2hex(random_bytes(24));
        yield 'Beacon worker key' => ['Header was ' . $a, [$a]];
        $a = $v();
        yield 'X-Beacon-Auth value' => ['Beacon beacon_key=pubFAKE, beacon_secret=' . $a, [$a]];
        // Re-verification of PR #28: N1 regression (filtered on 1.19.1) and camelCase names.
        $a = $v();
        yield 'clientSecret=' => ['OAuth error, clientSecret=' . $a . ' rejected', [$a]];
        $a = $v();
        yield 'clientsecret:' => ['clientsecret: ' . $a, [$a]];
        $a = $v();
        yield 'JSON clientSecret' => ['Body {"clientSecret":"' . $a . '"}', [$a]];
        $a = $v();
        yield 'camelCase dbPassword' => ['Config dbPassword=' . $a, [$a]];
        $a = $v();
        yield 'camelCase JSON apiToken' => ['Body {"apiToken":"' . $a . '"}', [$a]];
        $a = $v();
        yield 'URL password, empty user' => ['Cannot connect to redis://:' . $a . '@cache:6379', [$a]];
        $a = $v();
        yield 'JSON value with an escaped quote' => ['Body {"password":"ab\\"' . $a . '"}', [$a]];
        yield 'Amex, 15 digits' => ['Card 378282246310005 declined', ['378282246310005']];
        $a = $v();
        yield 'all-caps run myAPIToken' => ['Config myAPIToken=' . $a, [$a]];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('leakingMessages')]
    public function testRedactMessageCoversEnvStyleQuotedAndBeaconCredentials(string $message, array $secrets): void
    {
        $out = SensitiveValueRedactor::redactMessage($message);

        foreach ($secrets as $secret) {
            self::assertFalse(str_contains($out, $secret), $secret . ' leaked in ' . $out);
        }
        self::assertStringContainsString(SensitiveValueRedactor::FILTERED, $out);
    }

    public function testRedactMessageKeepsHarmlessText(): void
    {
        foreach ([
            'passenger=Bob shipping=fast discard=yes',
            'GET https://example.com:8443/users/@me failed',
            'Order 42 failed: stock=0',
            'beacon_key=pubFAKE is the public key',
        ] as $message) {
            self::assertSame($message, SensitiveValueRedactor::redactMessage($message));
        }

        self::assertSame('Cannot connect to postgresql://app:[Filtered]@db:5432/app', SensitiveValueRedactor::redactMessage('Cannot connect to postgresql://app:FAKEpgpw@db:5432/app'));
    }

    public function testRedactMessageFiltersLuhnValidCardNumbersOnly(): void
    {
        self::assertSame('Charge failed for card [Filtered] cvv=[Filtered]', SensitiveValueRedactor::redactMessage('Charge failed for card 4111111111111111 cvv=123'));
        self::assertSame('Card [Filtered] declined', SensitiveValueRedactor::redactMessage('Card 5500-0000-0000-0004 declined'));
        // Not Luhn-valid, or not a card prefix (millisecond timestamps, order ids): kept.
        foreach (['Order 4111111111111112 failed', 'at 1793553024389 ms', 'id 9111111111111111'] as $message) {
            self::assertSame($message, SensitiveValueRedactor::redactMessage($message));
        }
    }

    public function testRedactMapCoversShortCredentialKeys(): void
    {
        $out = SensitiveValueRedactor::redactMap([
            'db_pass'     => 'FAKE-2', 'pwd' => 'FAKE-3', 'pass' => 'FAKE-4', 'pin' => '4321', 'otp' => '987654', 'cvv' => '123',
            'card_number' => '4111111111111111', 'iban' => 'ES9121000418450200051332', 'cookie' => 'PHPSESSID=FAKEsess',
            'session_id'  => 'FAKEsess2', 'signature' => 'FAKEsig', 'private' => 'FAKE-5', 'passphrase' => 'FAKE-6',
            'passenger'   => 'Bob', 'shipping' => 'fast', 'discard' => 'yes', 'mapping' => 'm',
            'cardNumber'  => '4111111111111111', 'userPin' => '4321', 'dbPass' => 'FAKE-7', 'creditCard' => 'FAKE-8',
            'isPinned'    => true, 'PASSENGER' => 'Ann', 'OTPCode' => '987654',
        ]);

        foreach (['db_pass', 'pwd', 'pass', 'pin', 'otp', 'cvv', 'card_number', 'iban', 'cookie', 'session_id', 'signature', 'private', 'passphrase', 'cardNumber', 'userPin', 'dbPass', 'creditCard', 'OTPCode'] as $key) {
            self::assertSame(SensitiveValueRedactor::FILTERED, $out[$key], $key);
        }
        self::assertSame(['Bob', 'fast', 'yes', 'm', true, 'Ann'], [$out['passenger'], $out['shipping'], $out['discard'], $out['mapping'], $out['isPinned'], $out['PASSENGER']]);
    }

    public function testRedactQueryStringCoversShortCredentialParameters(): void
    {
        $out = SensitiveValueRedactor::redactQueryString('pass=FAKEqp&user=bob&pwd=FAKEqp2&key=FAKEgmapskey&session=FAKEsessq&reset=FAKEreset&sid=FAKEsid&page=2');

        self::assertSame('pass=[Filtered]&user=bob&pwd=[Filtered]&key=[Filtered]&session=[Filtered]&reset=[Filtered]&sid=[Filtered]&page=2', $out);
        self::assertFalse(str_contains((string) json_encode($out), 'FAKE'));
    }

    /**
     * Re-check of PR #28, N4: a huge quoted value after a secret name must not exhaust PCRE and leak the whole message.
     */
    public function testHugeQuotedValuesDoNotDisableRedaction(): void
    {
        $first  = 'Fk' . bin2hex(random_bytes(8));
        $second = 'bwk_' . bin2hex(random_bytes(24));
        $huge   = str_repeat('x', 1 << 20);

        foreach ([
            'DB_PASSWORD=' . $first . ' cfg {"password":"' . $huge . '"} sent ' . $second,
            'DB_PASSWORD=' . $first . " cfg ['password' => '" . $huge . "'] sent " . $second,
            'DB_PASSWORD=' . $first . ' cfg {"password":"' . $huge . ' unterminated, sent ' . $second,
            'DB_PASSWORD=' . $first . ' -----BEGIN RSA PRIVATE KEY-----' . $huge . ' sent ' . $second,
            'redis://u:' . $first . str_repeat('@x', 1 << 18) . ' sent ' . $second,
        ] as $index => $message) {
            $out = SensitiveValueRedactor::redactMessage($message);

            self::assertNotSame(SensitiveValueRedactor::REDACTION_FAILED, $out, (string) $index);
            self::assertFalse(str_contains($out, $first), $index . ': first secret leaked');
            self::assertFalse(str_contains($out, $second), $index . ': second secret leaked');
        }
    }

    /**
     * N4: when PCRE fails (backtrack limit, JIT off so the limit applies), redaction fails closed.
     */
    #[RunInSeparateProcess]
    public function testPcreErrorsFailClosed(): void
    {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        $secret = 'Fk' . bin2hex(random_bytes(8));

        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactMessage('DB_PASSWORD=' . $secret . ' and more text'));
        self::assertSame(['user' => SensitiveValueRedactor::FILTERED], SensitiveValueRedactor::redactMap(['user' => $secret]));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactValue('mysql://app:' . $secret . '@db/app'));
        self::assertStringNotContainsString($secret, SensitiveValueRedactor::redactQueryString('page=' . $secret));
    }
}
