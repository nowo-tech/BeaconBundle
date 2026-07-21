<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Instrumentation;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Instrumentation\TraceableBeaconHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
}
