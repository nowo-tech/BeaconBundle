<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Messenger\RemoteRetry;

use DateTimeImmutable;
use LogicException;
use Nowo\BeaconBundle\Messenger\RemoteRetry\CommandResult;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandException;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function str_repeat;

final class RemoteCommandClientTest extends TestCase
{
    public function testClaimParsesCommandsAndClampsPollAfter(): void
    {
        $body = json_encode([
            'commands' => [
                RemoteRetryFixtures::commandData(),
                ['id' => 'bad id with spaces', 'claim_token' => 'x'],
                ['id' => 'no-token'],
                'not an object',
                RemoteRetryFixtures::commandData(['id' => 'second', 'message_id' => 8813]),
            ],
            'poll_after' => 99999,
        ]);
        $client = $this->client(new MockResponse((string) $body));

        $claim = $client->claim([['name' => 'failed', 'listable' => true]]);

        self::assertCount(2, $claim->commands);
        self::assertSame('8813', $claim->commands[1]->messageId);
        self::assertSame(RemoteCommandClient::MAX_DELAY, $claim->pollAfter);
        self::assertSame('worker-2', $client->serverName());
    }

    public function testClaimToleratesEmptyOrOddBodies(): void
    {
        self::assertSame([], $this->client(new MockResponse(''))->claim([])->commands);
        $odd = $this->client(new MockResponse('{"commands":"nope","poll_after":"30"}'))->claim([]);
        self::assertSame([], $odd->commands);
        self::assertNull($odd->pollAfter);
        self::assertSame([], $this->client(new MockResponse('"just a string"'))->claim([])->commands);
    }

    /**
     * @return iterable<string, array{MockResponse|TransportException, string, ?int}>
     */
    public static function claimFailures(): iterable
    {
        yield '401' => [new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]), RemoteCommandException::KIND_AUTH, null];
        yield '403' => [new MockResponse('', ['http_code' => 403]), RemoteCommandException::KIND_AUTH, null];
        yield '404 older Beacon' => [new MockResponse('', ['http_code' => 404]), RemoteCommandException::KIND_AUTH, null];
        yield '429 seconds' => [new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 90']]), RemoteCommandException::KIND_RATE_LIMITED, 90];
        yield '429 huge' => [new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 999999']]), RemoteCommandException::KIND_RATE_LIMITED, RemoteCommandClient::MAX_DELAY];
        yield '503 date' => [new MockResponse('', ['http_code' => 503, 'response_headers' => ['Retry-After: Sat, 17 Oct 2026 10:05:00 GMT']]), RemoteCommandException::KIND_RATE_LIMITED, 120];
        yield '503 past date' => [new MockResponse('', ['http_code' => 503, 'response_headers' => ['Retry-After: Sat, 17 Oct 2026 09:00:00 GMT']]), RemoteCommandException::KIND_RATE_LIMITED, 1];
        yield '429 garbage' => [new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: soon']]), RemoteCommandException::KIND_RATE_LIMITED, null];
        yield '429 without header' => [new MockResponse('', ['http_code' => 429]), RemoteCommandException::KIND_RATE_LIMITED, null];
        yield '500' => [new MockResponse('', ['http_code' => 500]), RemoteCommandException::KIND_UNAVAILABLE, null];
        yield '400 on claim' => [new MockResponse('', ['http_code' => 400]), RemoteCommandException::KIND_UNAVAILABLE, null];
        yield 'not json' => [new MockResponse('<html>'), RemoteCommandException::KIND_UNAVAILABLE, null];
        yield 'too large' => [new MockResponse(str_repeat(' ', 70000)), RemoteCommandException::KIND_UNAVAILABLE, null];
        yield 'network' => [new TransportException('dns'), RemoteCommandException::KIND_UNAVAILABLE, null];
    }

    #[DataProvider('claimFailures')]
    public function testClaimFailures(MockResponse|TransportException $response, string $kind, ?int $retryAfter): void
    {
        $http = $response instanceof TransportException
            ? new MockHttpClient(static fn () => throw $response)
            : new MockHttpClient($response);

        try {
            $this->client(httpClient: $http)->claim([]);
            self::fail('Expected RemoteCommandException');
        } catch (RemoteCommandException $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($retryAfter, $e->retryAfter);
            self::assertStringNotContainsString(RemoteRetryFixtures::WORKER_KEY, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function resultStatuses(): iterable
    {
        yield '401' => [401, RemoteCommandException::KIND_AUTH];
        yield '404 unknown command' => [404, RemoteCommandException::KIND_REJECTED];
        yield '409 different result' => [409, RemoteCommandException::KIND_REJECTED];
        yield '408 timeout' => [408, RemoteCommandException::KIND_UNAVAILABLE];
        yield '425 too early' => [425, RemoteCommandException::KIND_UNAVAILABLE];
        yield '429' => [429, RemoteCommandException::KIND_RATE_LIMITED];
        yield '502' => [502, RemoteCommandException::KIND_UNAVAILABLE];
    }

    #[DataProvider('resultStatuses')]
    public function testResultStatuses(int $status, string $kind): void
    {
        $this->expectExceptionObject(new RemoteCommandException($kind, 'Beacon answered HTTP ' . $status . '.'));

        $this->client(new MockResponse('', ['http_code' => $status]))
            ->postResult(RemoteRetryFixtures::command(), CommandResult::done(CommandResult::OUTCOME_REMOVED, new DateTimeImmutable()));
    }

    public function testResultSuccessAndTlsOptions(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('{"state":"done"}');
        });
        $client = new RemoteCommandClient($http, RemoteRetryFixtures::credentials(), 'worker-2', 'prod', false);

        $client->postResult(RemoteRetryFixtures::command(), CommandResult::done(CommandResult::OUTCOME_REQUEUED, new DateTimeImmutable()));

        self::assertFalse($seen['verify_peer']);
        self::assertFalse($seen['verify_host']);
        self::assertSame(RemoteCommandClient::TIMEOUT, $seen['timeout']);
        self::assertStringStartsWith('User-Agent: beacon-bundle/', implode("\n", array_filter($seen['headers'], static fn (string $h): bool => str_starts_with($h, 'User-Agent'))));
    }

    public function testBundleVersionFallsBackToTheRawUserAgent(): void
    {
        $body = '';
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = $options['body'];

            return new MockResponse('{}');
        });

        (new RemoteCommandClient($http, RemoteRetryFixtures::credentials(), 'w', 'prod', true, null, 'custom-agent'))->claim([]);

        self::assertStringContainsString('"bundle_version":"custom-agent"', $body);
    }

    public function testMissingCredentialsNeverCallBeacon(): void
    {
        $http   = new MockHttpClient(static fn () => throw new LogicException('must not be called'));
        $client = new RemoteCommandClient($http, RemoteRetryFixtures::credentials(key: 'not-a-key'), 'w', 'prod');

        $this->expectException(RemoteCommandException::class);
        $client->claim([]);
    }

    private function client(?MockResponse $response = null, ?MockHttpClient $httpClient = null): RemoteCommandClient
    {
        return new RemoteCommandClient(
            $httpClient ?? new MockHttpClient($response ?? new MockResponse('{}')),
            RemoteRetryFixtures::credentials(),
            'worker-2',
            'prod',
            true,
            new MockClock(new DateTimeImmutable('2026-10-17T10:03:00Z')),
        );
    }
}
