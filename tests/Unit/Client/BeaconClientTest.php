<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Client;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\SendOptions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function is_array;
use function is_string;
use function strlen;

use const JSON_THROW_ON_ERROR;

final class BeaconClientTest extends TestCase
{
    public function testNullClientIsDisabled(): void
    {
        $client = new NullBeaconClient();
        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureMessage('x'));
        self::assertNull($client->captureException(new RuntimeException('x')));
        self::assertNull($client->captureTransaction('tx', 1.0, 2.0));
        $client->addBreadcrumb('noop');
        $client->setTag('k', 'v');
        $client->setTags(['a' => 'b']);
        self::assertSame([], $client->getTags());
        $client->clearTags();
    }

    public function testCaptureMessagePostsEnvelope(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });

        $dsn       = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com:9444/5');
        $transport = new EnvelopeTransport($http, $dsn, true, 2.0);
        $client    = new BeaconClient($transport, new EnvelopeBuilder('test'), true);

        $eventId = $client->captureMessage('hello', 'info', ['a' => 1]);
        self::assertNotNull($eventId);
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://beacon.example.com:9444/api/5/envelope/', $requests[0]['url']);

        $headers     = $requests[0]['options']['headers'] ?? [];
        $contentType = '';
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (is_string($key) && strcasecmp($key, 'Content-Type') === 0) {
                    $contentType = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                    break;
                }
                if (is_string($value) && str_starts_with(strtolower($value), 'content-type:')) {
                    $contentType = trim(substr($value, strlen('content-type:')));
                    break;
                }
            }
        }
        self::assertSame('application/x-beacon-envelope', $contentType);

        $body = (string) ($requests[0]['options']['body'] ?? '');
        self::assertStringContainsString('"dsn":"https://pubkey:secret@beacon.example.com:9444/5"', $body);
    }

    public function testBreadcrumbsAndTransaction(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $dsn     = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com:9444/5');
        $buffer  = new BreadcrumbBuffer();
        $builder = new EnvelopeBuilder('test', null, 'ci', new SendOptions(), null, $buffer);
        $client  = new BeaconClient(new EnvelopeTransport($http, $dsn), $builder, true, $buffer);

        $client->addBreadcrumb('pre');
        $txId = $client->captureTransaction('demo.tx', 1.0, 2.0, [], ['k' => 1]);
        self::assertNotNull($txId);
        self::assertStringContainsString('"type":"transaction"', (string) ($requests[0]['body'] ?? ''));
        self::assertStringContainsString('pre', (string) ($requests[0]['body'] ?? ''));
    }

    public function testCaptureExceptionPostsEnvelope(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });

        $dsn       = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/9');
        $transport = new EnvelopeTransport($http, $dsn, true, 2.0);
        $client    = new BeaconClient($transport, new EnvelopeBuilder('test', '1.2.3', 'ci-host'), true);

        $eventId = $client->captureException(new RuntimeException('broken'), ['job' => 'worker'], ['runtime', 'broken']);

        self::assertNotNull($eventId);
        self::assertCount(1, $requests);

        $body    = (string) ($requests[0]['options']['body'] ?? '');
        $lines   = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== ''));
        $header  = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($header['event_id'], $eventId);
        self::assertSame('broken', $payload['message']);
        self::assertSame(['job' => 'worker'], $payload['extra']);
        self::assertSame(['runtime', 'broken'], $payload['fingerprint']);
        self::assertArrayHasKey('exception', $payload);
    }

    public function testDisabledClientDoesNotSendEvents(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });

        $dsn       = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/9');
        $transport = new EnvelopeTransport($http, $dsn);
        $client    = new BeaconClient($transport, new EnvelopeBuilder('test'), false);

        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureMessage('hello'));
        self::assertNull($client->captureException(new RuntimeException('boom')));
        self::assertNull($client->captureTransaction('tx', 1.0, 2.0));
        self::assertCount(0, $requests);
    }

    public function testTagHelpersAreNoopWithoutScope(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/9');
        $client = new BeaconClient(
            new EnvelopeTransport(new MockHttpClient(new MockResponse('', ['http_code' => 200])), $dsn),
            new EnvelopeBuilder('test'),
        );

        $client->setTag('k', 'v');
        $client->setTags(['a' => 1]);
        self::assertSame([], $client->getTags());
        $client->clearTags();
    }

    public function testBeforeSendEdgeCasesViaReflection(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/9');
        // Invalid return type exercises the fail-soft path in applyBeforeSend.
        $beforeSend = static fn (array $event): mixed => 'not-an-array';
        $client     = new BeaconClient(
            new EnvelopeTransport(new MockHttpClient(new MockResponse('', ['http_code' => 200])), $dsn),
            new EnvelopeBuilder('test'),
            true,
            null,
            null,
            null,
            // @phpstan-ignore argument.type
            $beforeSend,
        );

        $apply = new ReflectionMethod($client, 'applyBeforeSend');
        $apply->setAccessible(true);

        self::assertSame("only-one-line\n", $apply->invoke($client, "only-one-line\n"));
        self::assertSame(
            "h\ni\nnot-json\n",
            $apply->invoke($client, "h\ni\nnot-json\n"),
        );
        self::assertNull($apply->invoke($client, "h\ni\n{\"message\":\"x\"}\n"));
    }

    public function testBeforeSendDropsCaptureException(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/9');
        $client = new BeaconClient(
            new EnvelopeTransport(new MockHttpClient(new MockResponse('', ['http_code' => 200])), $dsn),
            new EnvelopeBuilder('test'),
            true,
            null,
            null,
            null,
            static fn (array $event): ?array => null,
        );

        self::assertNull($client->captureException(new RuntimeException('dropped')));
        self::assertNull($client->captureTransaction('tx', 1.0, 2.0));
    }

    public function testGetDsnReturnsTransportDsn(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/9');
        $client = new BeaconClient(
            new EnvelopeTransport(new MockHttpClient(new MockResponse('', ['http_code' => 200])), $dsn),
            new EnvelopeBuilder('test'),
        );

        self::assertSame($dsn, $client->getDsn());
    }

    public function testExtractEventIdReturnsNullForFailurePaths(): void
    {
        $dsn    = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/9');
        $client = new BeaconClient(
            new EnvelopeTransport(new MockHttpClient(new MockResponse('', ['http_code' => 200])), $dsn),
            new EnvelopeBuilder('test'),
        );

        $extractEventId = new ReflectionMethod($client, 'extractEventId');
        $extractEventId->setAccessible(true);

        self::assertNull($extractEventId->invoke($client, ''));
        self::assertNull($extractEventId->invoke($client, "not-json\n"));
        self::assertNull($extractEventId->invoke($client, "{\"event_id\":123}\n"));
    }
}
