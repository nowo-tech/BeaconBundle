<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Client;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use const JSON_THROW_ON_ERROR;

final class BeaconClientTagsAndBeforeSendTest extends TestCase
{
    public function testTagsAreAttachedToEvents(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $scope   = new Scope();
        $builder = new EnvelopeBuilder('test', null, 'ci', new SendOptions(), null, null, null, 5, $scope);
        $dsn     = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/1');
        $client  = new BeaconClient(new EnvelopeTransport($http, $dsn), $builder, true, null, $scope);

        $client->setTags(['tenant' => 'acme', 'tier' => 'pro']);
        $client->captureMessage('hello', 'info');

        $body    = (string) ($requests[0]['body'] ?? '');
        $lines   = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== ''));
        $payload = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['tenant' => 'acme', 'tier' => 'pro'], $payload['tags']);
    }

    public function testBeforeSendCanMutateAndDrop(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $dsn     = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/1');
        $builder = new EnvelopeBuilder('test');

        $mutate = new BeaconClient(
            new EnvelopeTransport($http, $dsn),
            $builder,
            true,
            null,
            null,
            null,
            static function (array $event): array {
                unset($event['extra']['password']);
                $event['message'] = 'scrubbed';

                return $event;
            },
        );
        $mutate->captureMessage('raw', 'error', ['password' => 'secret', 'ok' => 1]);
        self::assertCount(1, $requests);
        $body    = (string) ($requests[0]['body'] ?? '');
        $lines   = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== ''));
        $payload = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('scrubbed', $payload['message']);
        self::assertSame(['ok' => 1], $payload['extra']);
        self::assertArrayNotHasKey('password', $payload['extra']);

        $requests = [];
        $drop     = new BeaconClient(
            new EnvelopeTransport($http, $dsn),
            $builder,
            true,
            null,
            null,
            null,
            static fn (array $event): ?array => null,
        );
        self::assertNull($drop->captureMessage('nope'));
        self::assertCount(0, $requests);

        $requests = [];
        $throws   = new BeaconClient(
            new EnvelopeTransport($http, $dsn),
            $builder,
            true,
            null,
            null,
            null,
            static function (array $event): array {
                throw new RuntimeException('hook boom');
            },
        );
        self::assertNull($throws->captureMessage('nope'));
        self::assertCount(0, $requests);
    }

    public function testCaptureTransactionDrainsSpanBuffer(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $spans   = new SpanBuffer();
        $crumbs  = new BreadcrumbBuffer();
        $builder = new EnvelopeBuilder('test', null, 'ci', new SendOptions(), null, $crumbs);
        $dsn     = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/1');
        $client  = new BeaconClient(new EnvelopeTransport($http, $dsn), $builder, true, $crumbs, null, $spans);

        $spans->add('db.sql.query', 'SELECT 1', 1.0, 1.05);
        $client->captureTransaction('demo', 1.0, 2.0);

        $body = (string) ($requests[0]['body'] ?? '');
        self::assertStringContainsString('"op":"db.sql.query"', $body);
        self::assertSame([], $spans->all());
    }
}
