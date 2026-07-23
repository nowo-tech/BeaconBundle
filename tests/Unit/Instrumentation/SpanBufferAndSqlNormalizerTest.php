<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Instrumentation;

use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Instrumentation\SqlNormalizer;
use PHPUnit\Framework\TestCase;

final class SpanBufferAndSqlNormalizerTest extends TestCase
{
    public function testSpanBufferDrainClears(): void
    {
        $buffer = new SpanBuffer();
        $buffer->add('db.sql.query', 'SELECT 1', 1.0, 1.1);
        $buffer->add('http.client', 'GET example.com', 1.1, 1.2);

        $spans = $buffer->drain();
        self::assertCount(2, $spans);
        self::assertSame('db.sql.query', $spans[0]['op']);
        self::assertSame([], $buffer->all());
    }

    public function testSpanBufferTrimsWhenOverMaxAndSupportsHelpers(): void
    {
        $buffer = new SpanBuffer();
        for ($i = 0; $i < 105; ++$i) {
            $buffer->add('op', 'desc-' . $i, 1.0, 1.1);
        }

        self::assertCount(100, $buffer->all());
        self::assertSame('desc-5', $buffer->all()[0]['description']);

        $buffer->clear();
        self::assertSame([], $buffer->all());

        $buffer->addTimed('http.client', 'GET x', 0.25, ['k' => 1], 10.0);
        self::assertCount(1, $buffer->all());
        self::assertEqualsWithDelta(9.75, $buffer->all()[0]['start_timestamp'], 0.0001);
        self::assertEqualsWithDelta(10.0, $buffer->all()[0]['timestamp'], 0.0001);

        $buffer->reset();
        self::assertSame([], $buffer->all());

        $buffer->addTimed('http.client', 'GET y', 0.1);
        self::assertCount(1, $buffer->all());
    }

    public function testSqlNormalizerScrubsAndTruncates(): void
    {
        $sql = "SELECT * FROM users WHERE email = 'secret@example.com' AND id = 1";
        $out = SqlNormalizer::normalize($sql);
        self::assertStringContainsString("'?'", $out);
        self::assertStringNotContainsString('secret@example.com', $out);

        $long = str_repeat('a', SqlNormalizer::MAX_SQL_LENGTH + 50);
        self::assertSame(SqlNormalizer::MAX_SQL_LENGTH, mb_strlen(SqlNormalizer::normalize($long)));
    }
}
