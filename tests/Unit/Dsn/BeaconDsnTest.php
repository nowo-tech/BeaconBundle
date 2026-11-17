<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Dsn;

use InvalidArgumentException;
use LogicException;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\Exception\BeaconExceptionInterface;
use Nowo\Beacon\Transport\IngestResponseHandler;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function print_r;
use function serialize;
use function strlen;
use function unserialize;
use function var_export;

/**
 * Since 1.27 {@see BeaconDsn} wraps beacon-php's final {@see Dsn} (composition) and keeps the same public methods.
 */
final class BeaconDsnTest extends TestCase
{
    public function testWrapsABeaconPhpDsnWithTheSameValues(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey:s3cr3t@beacon.example.com:9444/5');

        self::assertSame('https', $dsn->getScheme());
        self::assertSame('pubkey', $dsn->getPublicKey());
        self::assertSame('s3cr3t', $dsn->getSecretKey());
        self::assertSame('beacon.example.com', $dsn->getHost());
        self::assertSame(9444, $dsn->getPort());
        self::assertSame('5', $dsn->getProjectId());
        self::assertSame('https://beacon.example.com:9444', $dsn->getOrigin());
        self::assertSame('Beacon beacon_key=pubkey, beacon_secret=s3cr3t', $dsn->getBeaconAuthHeader());
        self::assertSame('https://pubkey:s3cr3t@beacon.example.com:9444/5', $dsn->toString());
        self::assertSame('https://pubkey@beacon.example.com:9444/5', $dsn->toPublicString());
        self::assertSame('https://beacon.example.com/api/5/envelope/', (new BeaconDsnParser())->parse('https://k:s@beacon.example.com/5')->getEnvelopeUrl());

        $core = $dsn->toCoreDsn();
        self::assertSame($core, $dsn->toCoreDsn());
        self::assertSame($dsn->toString(), $core->toString());
        self::assertSame($dsn->getBeaconAuthHeader(), $core->getBeaconAuthHeader());

        // beacon-php helpers that read the secret see the real one.
        self::assertSame('[Filtered] / [Filtered]', (new IngestResponseHandler($core))->sanitize('Beacon beacon_key=pubkey, beacon_secret=s3cr3t / s3cr3t'));
    }

    public function testFromCoreDsnKeepsEveryValue(): void
    {
        $dsn = BeaconDsn::fromCoreDsn(new Dsn('http', 'pub', 'sec', 'beacon.internal', null, 7));

        self::assertSame('http://pub:sec@beacon.internal/7', $dsn->toString());
        self::assertNull($dsn->getPort());
        self::assertSame('7', $dsn->getProjectId());
    }

    public function testTheSecretIsNeverExported(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey:s3cr3t@beacon.example.com/5');

        self::assertStringNotContainsString('s3cr3t', var_export($dsn, true));
        self::assertStringNotContainsString('s3cr3t', print_r($dsn, true));
        self::assertSame(['origin' => 'https://beacon.example.com', 'project_id' => '5', 'public_key' => 'pubkey', 'secret_key' => '***'], $dsn->__debugInfo());
    }

    public function testCannotBeSerialized(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey:s3cr3t@beacon.example.com/5');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be serialized');
        serialize($dsn);
    }

    public function testCannotBeUnserialized(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be unserialized');
        unserialize('O:' . strlen(BeaconDsn::class) . ':"' . BeaconDsn::class . '":0:{}');
    }

    public function testParserExceptionsAreBeaconPhpExceptionsWithTheCoreOneAsPrevious(): void
    {
        try {
            (new BeaconDsnParser())->parse('ftp://k:s@host/1');
            self::fail('Expected an invalid DSN.');
        } catch (InvalidBeaconDsnException $exception) {
            $class = new ReflectionClass($exception);
            self::assertTrue($class->implementsInterface(BeaconExceptionInterface::class));
            self::assertTrue($class->isSubclassOf(InvalidArgumentException::class));
            self::assertSame('Unsupported DSN scheme "ftp". Use http or https.', $exception->getMessage());

            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidDsnException::class, $previous);
            self::assertSame($exception->getMessage(), $previous->getMessage());
        }
    }
}
