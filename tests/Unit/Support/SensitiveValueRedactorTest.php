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
}
