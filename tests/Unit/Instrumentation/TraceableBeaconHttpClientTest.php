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
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TraceableBeaconHttpClientTest extends TestCase
{
    public function testRecordsSpanAndBreadcrumbForOutboundRequest(): void
    {
        $inner  = new MockHttpClient(new MockResponse('ok', ['http_code' => 200]));
        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();
        $client = new TraceableBeaconHttpClient($inner, $spans, $crumbs);

        $response = $client->request('GET', 'https://api.example.com/v1/items');
        self::assertSame([], $spans->all(), 'nothing is read inside request()');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $spans->all());
        self::assertSame(200, $spans->all()[0]['data']['http.status_code'] ?? null);
        self::assertSame('http.client', $spans->all()[0]['op']);
        self::assertStringContainsString('GET api.example.com', $spans->all()[0]['description']);
        self::assertCount(1, $crumbs->all());
    }

    public function testSkipsBeaconEnvelopeUrls(): void
    {
        $inner  = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));
        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();
        $client = new TraceableBeaconHttpClient($inner, $spans, $crumbs);

        self::assertSame(200, $client->request('POST', 'https://beacon.example.com/api/1/envelope/')->getStatusCode());
        $client->request('POST', 'https://beacon.example.com/api/1/messenger/commands/claim')->getContent();

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

    public function testAnOuterAsyncDecoratorCanWrapIt(): void
    {
        // 1.18.1: NoPrivateNetworkHttpClient / RetryableHttpClient wrap the response in an AsyncResponse; reading it
        // inside request() made them throw "A decorated client should not call any of the response's methods".
        $spans  = new SpanBuffer();
        $client = new RetryableHttpClient(new TraceableBeaconHttpClient(
            new MockHttpClient([new MockResponse('down', ['http_code' => 503]), new MockResponse('up', ['http_code' => 200])]),
            $spans,
            new BreadcrumbBuffer(),
        ), null, 1);

        $response = $client->request('GET', 'https://status.example.com/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('up', $response->getContent());
        self::assertSame([503, 200], array_map(static fn (array $span): mixed => $span['data']['http.status_code'] ?? null, $spans->all()));
    }

    public function testRecordsATransportErrorOnce(): void
    {
        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();
        $client = new TraceableBeaconHttpClient(new MockHttpClient(new MockResponse('', ['error' => 'connection refused'])), $spans, $crumbs);

        $response = $client->request('GET', 'https://down.example.com/');
        try {
            $response->getStatusCode();
            self::fail('Expected a transport error');
        } catch (TransportExceptionInterface) {
        }

        self::assertCount(1, $spans->all());
        self::assertStringContainsString('connection refused', (string) ($spans->all()[0]['data']['error'] ?? ''));
        self::assertSame('error', $crumbs->all()[0]['level'] ?? null);
    }

    public function testStreamAndWithOptionsKeepTracing(): void
    {
        $spans  = new SpanBuffer();
        $client = new TraceableBeaconHttpClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('body', ['http_code' => 201])),
            $spans,
            new BreadcrumbBuffer(),
        );
        $cloned = $client->withOptions(['timeout' => 1.0]);
        self::assertNotSame($client, $cloned);

        $response = $cloned->request('GET', 'https://api.example.com/stream');
        $content  = '';
        foreach ($cloned->stream($response) as $chunk) {
            $content .= $chunk->getContent();
        }

        self::assertSame('body', $content);
        self::assertSame(201, $spans->all()[0]['data']['http.status_code'] ?? null);
        $client->reset();
    }
}
