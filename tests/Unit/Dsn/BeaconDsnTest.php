<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Dsn;

use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\Transport\IngestResponseHandler;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function print_r;
use function var_export;

final class BeaconDsnTest extends TestCase
{
    public function testIsABeaconPhpDsnWithTheSameValues(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey:s3cr3t@beacon.example.com:9444/5');

        self::assertTrue((new ReflectionClass($dsn))->isSubclassOf(Dsn::class));
        self::assertSame('s3cr3t', $dsn->getSecretKey());
        self::assertSame('Beacon beacon_key=pubkey, beacon_secret=s3cr3t', $dsn->getBeaconAuthHeader());
        self::assertSame('https://pubkey:s3cr3t@beacon.example.com:9444/5', $dsn->toString());
        self::assertSame('https://pubkey@beacon.example.com:9444/5', $dsn->toPublicString());
        self::assertSame('https://beacon.example.com/api/5/envelope/', (new BeaconDsnParser())->parse('https://k:s@beacon.example.com/5')->getEnvelopeUrl());

        // beacon-php helpers that read the secret polymorphically see the real one.
        self::assertSame('[Filtered] / [Filtered]', (new IngestResponseHandler($dsn))->sanitize('Beacon beacon_key=pubkey, beacon_secret=s3cr3t / s3cr3t'));
    }

    public function testTheSecretIsNotInExportsOfTheParentState(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey:s3cr3t@beacon.example.com/5');

        self::assertStringNotContainsString('s3cr3t', var_export($dsn, true));
        self::assertStringNotContainsString('s3cr3t', print_r($dsn, true));
    }

    public function testParserExceptionsAreBeaconPhpExceptions(): void
    {
        try {
            (new BeaconDsnParser())->parse('ftp://k:s@host/1');
            self::fail('Expected an invalid DSN.');
        } catch (InvalidBeaconDsnException $exception) {
            self::assertTrue((new ReflectionClass($exception))->isSubclassOf(InvalidDsnException::class));
            self::assertSame('Unsupported DSN scheme "ftp". Use http or https.', $exception->getMessage());
        }
    }
}
