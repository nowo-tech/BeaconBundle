<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Support;

use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use PHPUnit\Framework\TestCase;
use stdClass;

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
}
