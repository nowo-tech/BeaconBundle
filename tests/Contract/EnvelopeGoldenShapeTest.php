<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Contract;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function explode;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function rtrim;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Locks the Bundle-produced Envelope NDJSON shape against golden fixtures (Phase 3.6).
 *
 * Fixtures are mirrored in symfony-beacon/tests/Ingest/fixtures/envelope/.
 */
final class EnvelopeGoldenShapeTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/fixtures/envelope';

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fixtureProvider(): iterable
    {
        yield 'event_happy' => ['event_happy.ndjson', 'event'];
        yield 'event_exception' => ['event_exception.ndjson', 'event'];
        yield 'transaction_with_spans' => ['transaction_with_spans.ndjson', 'transaction'];
    }

    #[DataProvider('fixtureProvider')]
    public function testGoldenFixtureHasThreeNdjsonLinesAndRequiredKeys(string $file, string $expectedType): void
    {
        $path = self::FIXTURES_DIR . '/' . $file;
        $raw  = file_get_contents($path);
        self::assertNotFalse($raw);
        self::assertStringEndsWith("\n", $raw);

        $lines = explode("\n", rtrim($raw, "\n"));
        self::assertCount(3, $lines, sprintf('%s must be exactly 3 NDJSON lines', $file));

        /** @var array<string, mixed> $header */
        $header = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $item */
        $item = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $header['event_id']);
        self::assertIsString($header['dsn']);
        self::assertStringContainsString('://', $header['dsn']);
        self::assertStringContainsString(':', $header['dsn']); // secret required in DSN
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            (string) $header['sent_at'],
        );

        self::assertSame($expectedType, $item['type']);
        self::assertSame('application/json', $item['content_type']);

        self::assertSame($header['event_id'], $payload['event_id']);
        self::assertSame('php', $payload['platform']);

        if ($expectedType === 'event') {
            self::assertIsString($payload['message']);
            self::assertIsString($payload['level']);
            self::assertIsNumeric($payload['timestamp']);
            self::assertIsString($payload['datetime']);
            if (isset($payload['exception'])) {
                self::assertIsArray($payload['exception']['values'] ?? null);
                self::assertGreaterThan(0, count($payload['exception']['values']));
            }
        }

        if ($expectedType === 'transaction') {
            self::assertSame('transaction', $payload['type']);
            self::assertIsString($payload['transaction']);
            self::assertIsNumeric($payload['start_timestamp']);
            self::assertIsNumeric($payload['timestamp']);
            self::assertIsArray($payload['spans']);
            self::assertNotEmpty($payload['spans']);
            foreach ($payload['spans'] as $span) {
                self::assertIsArray($span);
                self::assertIsString($span['op'] ?? null);
                self::assertIsString($span['description'] ?? null);
                self::assertIsString($span['span_id'] ?? null);
                self::assertIsNumeric($span['start_timestamp'] ?? null);
                self::assertIsNumeric($span['timestamp'] ?? null);
            }
        }
    }

    public function testAuthHeaderContractMatchesBeaconParserExpectation(): void
    {
        $dsn = (new BeaconDsnParser())->parse(
            'https://pubkey:secret@beacon.example.com:9444/1',
        );

        self::assertSame(
            'Beacon beacon_key=pubkey, beacon_secret=secret',
            $dsn->getBeaconAuthHeader(),
        );
    }

    public function testLiveBuilderEventShapeAlignsWithGoldenRequiredKeys(): void
    {
        $dsn = (new BeaconDsnParser())->parse(
            'https://pubkey:secret@beacon.example.com:9444/1',
        );
        $builder = new EnvelopeBuilder('test', '1.2.3', 'ci-host');
        $body    = $builder->buildEventEnvelope($dsn, 'Something broke', 'error');

        $lines = explode("\n", rtrim($body, "\n"));
        self::assertCount(3, $lines);

        $header  = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $item    = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue(is_array($header) && is_array($item) && is_array($payload));
        self::assertSame('event', $item['type']);
        self::assertSame('application/json', $item['content_type']);
        self::assertTrue(is_string($header['dsn']));
        self::assertSame($dsn->toString(), $header['dsn']);
        self::assertSame('Something broke', $payload['message']);
        self::assertSame('php', $payload['platform']);
        self::assertArrayHasKey('timestamp', $payload);
        self::assertArrayHasKey('datetime', $payload);
    }
}
