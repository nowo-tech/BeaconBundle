<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessage;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SendBeaconEnvelopeMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEnvelopeBody(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });
        $dsn       = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $transport = new EnvelopeTransport($http, $dsn, true, 5.0, null, 'beacon-bundle/test');
        $handler   = new SendBeaconEnvelopeMessageHandler($transport);

        $handler(new SendBeaconEnvelopeMessage("header\nitem\npayload\n"));

        self::assertCount(1, $requests);
        self::assertSame("header\nitem\npayload\n", $requests[0]['body'] ?? null);
    }
}
