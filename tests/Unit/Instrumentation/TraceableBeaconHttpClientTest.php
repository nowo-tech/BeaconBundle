<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Instrumentation;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Instrumentation\TraceableBeaconHttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class TraceableBeaconHttpClientTest extends TestCase
{
    public function testRecordsSpanAndBreadcrumbForOutboundRequest(): void
    {
        $inner  = new MockHttpClient(new MockResponse('ok', ['http_code' => 200]));
        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();
        $client = new TraceableBeaconHttpClient($inner, $spans, $crumbs);

        $client->request('GET', 'https://api.example.com/v1/items');

        self::assertCount(1, $spans->all());
        self::assertSame('http.client', $spans->all()[0]['op']);
        self::assertStringContainsString('GET api.example.com', $spans->all()[0]['description']);
        self::assertCount(1, $crumbs->all());
    }

    public function testSkipsBeaconEnvelopeUrls(): void
    {
        $inner  = new MockHttpClient(new MockResponse('', ['http_code' => 200]));
        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();
        $client = new TraceableBeaconHttpClient($inner, $spans, $crumbs);

        $client->request('POST', 'https://beacon.example.com/api/1/envelope/');

        self::assertSame([], $spans->all());
        self::assertSame([], $crumbs->all());
    }

    public function testSkipsWhenUserAgentHeaderIsBeaconBundle(): void
    {
        $inner  = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));
        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();
        $client = new TraceableBeaconHttpClient($inner, $spans, $crumbs);

        $client->request('POST', 'https://api.example.com/ingest', [
            'headers' => ['User-Agent' => 'beacon-bundle/1.6.0'],
        ]);
        self::assertSame([], $spans->all());

        $client->request('POST', 'https://api.example.com/ingest', [
            'headers' => ['User-Agent' => ['beacon-bundle/1.6.0']],
        ]);
        self::assertSame([], $spans->all());
    }

    public function testShouldSkipHandlesListStyleUserAgentAndNonArrayHeaders(): void
    {
        $client = new TraceableBeaconHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
            new SpanBuffer(),
            new BreadcrumbBuffer(),
        );
        $method = new ReflectionMethod($client, 'shouldSkip');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($client, 'https://api.example.com/x', ['headers' => 'not-an-array']));
        self::assertTrue($method->invoke($client, 'https://api.example.com/x', [
            'headers' => ['user-agent: beacon-bundle/1.6.0'],
        ]));
    }

    public function testRecordsErrorWhenRequestThrows(): void
    {
        $inner = $this->createMock(HttpClientInterface::class);
        $inner->method('request')->willThrowException(new RuntimeException('network boom'));

        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();
        $client = new TraceableBeaconHttpClient($inner, $spans, $crumbs);

        try {
            $client->request('GET', 'https://api.example.com/fail');
            self::fail('Expected exception');
        } catch (RuntimeException $exception) {
            self::assertSame('network boom', $exception->getMessage());
        }

        self::assertCount(1, $spans->all());
        self::assertSame('network boom', $spans->all()[0]['data']['error'] ?? null);
    }

    public function testStreamAndWithOptionsDelegate(): void
    {
        $stream = $this->createMock(ResponseStreamInterface::class);
        $inner  = $this->createMock(HttpClientInterface::class);
        $inner->expects(self::once())->method('stream')->willReturn($stream);
        $inner->expects(self::once())->method('withOptions')->with(['timeout' => 1.0])->willReturn($inner);

        $client   = new TraceableBeaconHttpClient($inner, new SpanBuffer(), new BreadcrumbBuffer());
        $cloned   = $client->withOptions(['timeout' => 1.0]);
        $response = $this->createMock(ResponseInterface::class);

        self::assertNotSame($client, $cloned);
        self::assertSame($stream, $cloned->stream($response));
    }
}
