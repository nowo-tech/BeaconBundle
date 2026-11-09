<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Client;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Nowo\BeaconBundle\Scope\Scope;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function explode;
use function json_decode;
use function rtrim;

use const JSON_THROW_ON_ERROR;

/**
 * Owner decision 2026-11-09 (1.21): beacon-php's client-side scrubber runs before `before_send`, so the hook never
 * sees what it removes, and nothing removed reaches Beacon.
 */
final class BeaconClientScrubberTest extends TestCase
{
    /** @var list<string> */
    private array $bodies = [];

    public function testEventPayloadIsScrubbedBeforeBeforeSend(): void
    {
        $seen   = null;
        $client = $this->client(static function (array $event) use (&$seen): array {
            $seen = $event;

            return $event;
        });

        $client->setTag('session_id', 'tag-session-value');
        $client->addBreadcrumb('login card 4111 1111 1111 1111', 'auth', 'info', ['password' => 'crumb-pw', 'url' => 'https://u:crumb-url-pw@example.com/', 'items' => 2]);
        $client->captureException(new RuntimeException('charge failed'), [
            'order'   => 42,
            'cookie'  => 'extra-cookie',
            'card_no' => 4111111111111111,
            'notes'   => 'token=extra-token-value Bearer abcdefgh12345678',
            'nested'  => ['csrf_token' => 'nested-csrf', 'keep' => 'kept'],
        ]);

        self::assertIsArray($seen);
        $payload = $this->sentPayload();
        self::assertSame($seen, $payload, 'before_send must return what was sent');

        self::assertSame(42, $payload['extra']['order']);
        self::assertSame('[Filtered]', $payload['extra']['cookie']);
        self::assertSame('[Filtered]', $payload['extra']['card_no']);
        self::assertSame('token=[Filtered] Bearer [Filtered]', $payload['extra']['notes']);
        self::assertSame(['csrf_token' => '[Filtered]', 'keep' => 'kept'], $payload['extra']['nested']);
        self::assertSame('[Filtered]', $payload['tags']['session_id']);
        self::assertSame('login card [Filtered]', $payload['breadcrumbs']['values'][0]['message']);
        self::assertSame('[Filtered]', $payload['breadcrumbs']['values'][0]['data']['password']);
        self::assertSame('https://[Filtered]@example.com/', $payload['breadcrumbs']['values'][0]['data']['url']);
        self::assertSame(2, $payload['breadcrumbs']['values'][0]['data']['items']);

        foreach (['tag-session-value', 'crumb-pw', 'crumb-url-pw', 'extra-cookie', 'extra-token-value', 'nested-csrf', '4111'] as $secret) {
            self::assertStringNotContainsString($secret, $this->bodies[0]);
        }
    }

    public function testScrubbingAlsoAppliesWithoutBeforeSendAndToTransactions(): void
    {
        $client = $this->client(null);

        $client->captureTransaction('GET /pay', 1.0, 2.0, [], ['api_key' => 'tx-key']);

        self::assertSame('[Filtered]', $this->sentPayload()['extra']['api_key']);
        self::assertStringNotContainsString('tx-key', $this->bodies[0]);
    }

    /**
     * @param (callable(array<string, mixed>): ?array<string, mixed>)|null $beforeSend
     */
    private function client(?callable $beforeSend): BeaconClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->bodies[] = (string) $options['body'];

            return new MockResponse('', ['http_code' => 200]);
        });
        $dsn     = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/1');
        $scope   = new Scope();
        $crumbs  = new BreadcrumbBuffer();
        $builder = new EnvelopeBuilder('test', null, 'ci', new SendOptions(stacktrace: false), null, $crumbs, null, 5, $scope);

        return new BeaconClient(new EnvelopeTransport($http, $dsn), $builder, true, $crumbs, $scope, null, $beforeSend);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        self::assertCount(1, $this->bodies);
        $lines = explode("\n", rtrim($this->bodies[0], "\n"));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
