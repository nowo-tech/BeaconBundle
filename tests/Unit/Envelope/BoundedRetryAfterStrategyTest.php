<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Envelope\BoundedRetryAfterStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

use function gmdate;
use function time;

final class BoundedRetryAfterStrategyTest extends TestCase
{
    public function testReadsRetryAfterLikeRetryableHttpClient(): void
    {
        self::assertNull(BoundedRetryAfterStrategy::retryAfterMs([]));
        self::assertSame(2500, BoundedRetryAfterStrategy::retryAfterMs(['retry-after' => ['2.5']]));
        self::assertNull(BoundedRetryAfterStrategy::retryAfterMs(['retry-after' => ['not a date']]));
        self::assertSame(0, BoundedRetryAfterStrategy::retryAfterMs(['retry-after' => [gmdate('D, d M Y H:i:s', time() - 60) . ' GMT']]));
        self::assertGreaterThanOrEqual(
            299000,
            BoundedRetryAfterStrategy::retryAfterMs(['retry-after' => [gmdate('D, d M Y H:i:s', time() + 300) . ' GMT']]),
        );
    }

    public function testWithoutRetryAfterTheInnerBackoffIsUsed(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503, 'response_headers' => ['Retry-After: not a date']]),
            new MockResponse('', ['http_code' => 202]),
        ]);
        $client = new RetryableHttpClient(
            $httpClient,
            new BoundedRetryAfterStrategy(new GenericRetryStrategy([503], 0, 1.0, 1000, 0.0), 1000),
            2,
        );

        self::assertSame(202, $client->request('POST', 'https://beacon.example.com/api/5/envelope/')->getStatusCode());
        self::assertSame(3, $httpClient->getRequestsCount());
    }

    public function testStatusCodesTheInnerStrategyDeclinesAreNotRetried(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 400, 'response_headers' => ['Retry-After: 0']]),
            new MockResponse('', ['http_code' => 202]),
        ]);
        $client = new RetryableHttpClient(
            $httpClient,
            new BoundedRetryAfterStrategy(new GenericRetryStrategy([503], 0, 1.0, 1000, 0.0), 1000),
            2,
        );

        self::assertSame(400, $client->request('POST', 'https://beacon.example.com/api/5/envelope/')->getStatusCode());
        self::assertSame(1, $httpClient->getRequestsCount());
    }
}
