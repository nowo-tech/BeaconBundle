<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Envelope\ExplicitTlsHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ExplicitTlsHttpClientTest extends TestCase
{
    public function testEveryRequestCarriesTheTlsOptionsAndWithOptionsKeepsThem(): void
    {
        $seen  = [];
        $inner = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$options['verify_peer'], $options['verify_host'], $options['headers']];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $client  = new ExplicitTlsHttpClient($inner, true);
        $derived = $client->withOptions(['verify_peer' => false, 'headers' => ['X-Test' => '1']]);

        $response = $derived->request('GET', 'https://beacon.example.com/', ['verify_peer' => false, 'verify_host' => false]);
        foreach ($derived->stream($response) as $chunk) {
            if ($chunk->isLast()) {
                break;
            }
        }

        self::assertSame('ok', $response->getContent());
        self::assertTrue($seen[0][0]);
        self::assertTrue($seen[0][1]);
        self::assertContains('X-Test: 1', $seen[0][2]);
    }
}
