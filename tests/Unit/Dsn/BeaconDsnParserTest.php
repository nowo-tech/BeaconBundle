<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Dsn;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use PHPUnit\Framework\TestCase;

use function sprintf;

final class BeaconDsnParserTest extends TestCase
{
    private BeaconDsnParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BeaconDsnParser();
    }

    public function testParsesHostPortAndProject(): void
    {
        $dsn = $this->parser->parse('https://9cb5e28adc3ed7a40052e2a17e327220@localhost:9444/1');

        self::assertSame('https', $dsn->getScheme());
        self::assertSame('9cb5e28adc3ed7a40052e2a17e327220', $dsn->getPublicKey());
        self::assertNull($dsn->getSecretKey());
        self::assertSame('localhost', $dsn->getHost());
        self::assertSame(9444, $dsn->getPort());
        self::assertSame(1, $dsn->getProjectId());
        self::assertSame('https://localhost:9444', $dsn->getOrigin());
        self::assertSame('https://localhost:9444/api/1/envelope/', $dsn->getEnvelopeUrl());
    }

    public function testParsesHttpScheme(): void
    {
        $dsn = $this->parser->parse('http://public@beacon.internal:9081/2');

        self::assertSame('http', $dsn->getScheme());
        self::assertSame('public', $dsn->getPublicKey());
        self::assertNull($dsn->getSecretKey());
        self::assertSame('beacon.internal', $dsn->getHost());
        self::assertSame(9081, $dsn->getPort());
        self::assertSame(2, $dsn->getProjectId());
        self::assertSame('http://beacon.internal:9081', $dsn->getOrigin());
    }

    public function testParsesSubdomainWithoutPort(): void
    {
        $dsn = $this->parser->parse('https://abc@errors.example.com/3');

        self::assertSame('errors.example.com', $dsn->getHost());
        self::assertNull($dsn->getPort());
        self::assertSame(3, $dsn->getProjectId());
        self::assertSame('https://errors.example.com', $dsn->getOrigin());
        self::assertSame('https://errors.example.com/api/3/envelope/', $dsn->getEnvelopeUrl());
    }

    public function testParsesOptionalSecret(): void
    {
        $dsn = $this->parser->parse('http://public:secret@beacon.internal:9081/2');

        self::assertSame('http', $dsn->getScheme());
        self::assertSame('public', $dsn->getPublicKey());
        self::assertSame('secret', $dsn->getSecretKey());
        self::assertSame('http://public:secret@beacon.internal:9081/2', $dsn->toString());
    }

    public function testTreatsEmptySecretAsNull(): void
    {
        $dsn = $this->parser->parse('https://public:@host/9');

        self::assertSame('public', $dsn->getPublicKey());
        self::assertNull($dsn->getSecretKey());
        self::assertSame('https://public@host/9', $dsn->toString());
    }

    public function testRejectsInvalidDsns(): void
    {
        foreach ($this->invalidDsnProvider() as [$rawDsn, $expectedMessage]) {
            try {
                $this->parser->parse($rawDsn);
                self::fail(sprintf('Expected parsing "%s" to fail.', $rawDsn));
            } catch (InvalidBeaconDsnException $exception) {
                self::assertStringContainsString($expectedMessage, $exception->getMessage());
            }
        }
    }

    public function testRejectsInvalidPorts(): void
    {
        $this->expectException(InvalidBeaconDsnException::class);
        $this->parser->parse('https://key@host:0/1');
    }

    public function testRejectsPortAboveRange(): void
    {
        $this->expectException(InvalidBeaconDsnException::class);
        $this->parser->parse('https://key@host:70000/1');
    }

    public function testToStringRoundTripWithSecret(): void
    {
        $raw = 'https://key:secret@beacon.example.com:9444/7';
        $dsn = $this->parser->parse($raw);

        self::assertSame($raw, $dsn->toString());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function invalidDsnProvider(): array
    {
        return [
            ['   ', 'DSN must not be empty.'],
            ['ftp://key@host/1', 'Unsupported DSN scheme'],
            ['https://:secret@host/1', 'public key'],
            ['https://host/1', 'Invalid DSN'],
            ['https://key@host/not-a-number', 'numeric project id'],
            ['https://key@host/0', 'positive integer'],
        ];
    }
}
