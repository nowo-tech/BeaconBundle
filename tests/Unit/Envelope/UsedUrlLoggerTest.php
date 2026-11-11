<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Envelope\UsedUrlLogger;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class UsedUrlLoggerTest extends TestCase
{
    public function testEveryLevelForwardsWithTheUsedUrl(): void
    {
        $inner  = new ArrayLogger();
        $logger = new UsedUrlLogger($inner, 'https://b.example/api/ingest/v1/5/envelope/');

        $logger->emergency('m', ['url' => 'legacy']);
        $logger->alert('m', ['url' => 'legacy']);
        $logger->critical('m', ['url' => 'legacy']);
        $logger->error('m', ['url' => 'legacy']);
        $logger->warning('m', ['url' => 'legacy']);
        $logger->notice('m', ['url' => 'legacy']);
        $logger->info('m', ['url' => 'legacy']);
        $logger->debug('m', ['url' => 'legacy']);
        $logger->log(LogLevel::WARNING, 'm', ['status' => 503]);

        self::assertCount(9, $inner->records);
        for ($i = 0; $i < 8; ++$i) {
            self::assertSame('https://b.example/api/ingest/v1/5/envelope/', $inner->records[$i]['context']['url']);
        }
        self::assertSame(['status' => 503], $inner->records[8]['context'], 'no url key is added');
    }
}
