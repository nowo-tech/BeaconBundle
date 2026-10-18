<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Contract;

use DateTimeImmutable;
use Nowo\BeaconBundle\Messenger\RemoteRetry\FailedMessageCommandExecutor;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommand;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\InvoiceMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\ScriptedListableTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function bin2hex;
use function file_get_contents;
use function hash;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Locks the claim / result wire format and the signing vectors shared with Symfony Beacon
 * (`tests/Contract/fixtures/messenger_commands/`).
 */
final class MessengerCommandsContractTest extends TestCase
{
    public function testSigningVectors(): void
    {
        $vectors = $this->fixture('signing.json');
        $key     = new WorkerKey($vectors['worker_key']);

        self::assertSame($vectors['auth_subkey_hex'], bin2hex($key->authKey()));
        self::assertSame($vectors['auth_subkey_sha256_hex'], hash('sha256', $key->authKey()));
        self::assertSame($vectors['sign_subkey_hex'], bin2hex($key->signingKey()));
        self::assertSame($vectors['authorization_header'], $key->authorizationHeader());

        $command = RemoteRetryFixtures::command(['claim_token' => 'x']);
        self::assertSame($vectors['canonical'], $command->canonicalString());
        self::assertSame($vectors['signature'], $command->expectedSignature($key->signingKey()));
        self::assertSame($vectors['signature'], $this->fixture('claim_response.json')['commands'][0]['signature']);
    }

    public function testClaimAndResultBodies(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return str_ends_with($url, '/claim')
                ? new MockResponse((string) file_get_contents(RemoteRetryFixtures::CONTRACT_DIR . '/claim_response.json'))
                : new MockResponse('{"state":"done"}');
        });

        $clock  = new MockClock(new DateTimeImmutable('2026-10-17T10:02:41Z'));
        $client = new RemoteCommandClient($http, RemoteRetryFixtures::credentials(), 'worker-2', 'prod', true, $clock, 'beacon-bundle/1.16.0');

        $failed                   = new ScriptedListableTransport();
        $failed->messages['8812'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);
        $async                    = new ScriptedListableTransport();
        $executor                 = new FailedMessageCommandExecutor(
            RemoteRetryFixtures::credentials(),
            new ServiceLocator(['failed' => static fn (): ScriptedListableTransport => $failed, 'async' => static fn (): ScriptedListableTransport => $async]),
            ['retry', 'discard'],
            ['failed'],
            null,
            $clock,
        );

        $claim = $client->claim($executor->describeFailureTransports());
        self::assertCount(1, $claim->commands);
        self::assertSame(30, $claim->pollAfter);

        $client->postResult($claim->commands[0], $executor->execute($claim->commands[0]));

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://beacon.example.com:9447/api/' . RemoteRetryFixtures::PROJECT . '/messenger/commands/claim', $requests[0]['url']);
        self::assertSame($this->fixture('claim_request.json'), json_decode($requests[0]['options']['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertContains('Authorization: Bearer ' . RemoteRetryFixtures::WORKER_KEY, $requests[0]['options']['headers']);
        self::assertContains('Content-Type: application/json', $requests[0]['options']['headers']);

        self::assertSame(
            'https://beacon.example.com:9447/api/' . RemoteRetryFixtures::PROJECT . '/messenger/commands/0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f6/result',
            $requests[1]['url'],
        );
        self::assertSame($this->fixture('result_done.json'), json_decode($requests[1]['options']['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testFailedResultBody(): void
    {
        $clock                    = new MockClock(new DateTimeImmutable('2026-10-17T10:02:41Z'));
        $failed                   = new ScriptedListableTransport();
        $failed->messages['8812'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);
        $async                    = new class extends InMemoryTransport {
            public function send(Envelope $envelope): Envelope
            {
                throw new TransportException('broker down');
            }
        };
        $executor = new FailedMessageCommandExecutor(
            RemoteRetryFixtures::credentials(),
            new ServiceLocator(['failed' => static fn (): ScriptedListableTransport => $failed, 'async' => static fn (): object => $async]),
            ['retry'],
            ['failed'],
            null,
            $clock,
        );
        $command = RemoteRetryFixtures::command(['claim_token' => 'q2V0dGhpcy1pcy1hLXRlc3QtY2xhaW0tdG9rZW4tMDE']);

        self::assertSame($this->fixture('result_failed.json'), $executor->execute($command)->toPayload($command->claimToken, 'worker-2'));
        self::assertSame([], $failed->rejected, 'nothing is removed when the re-send fails');
    }

    public function testDiscardFixtureVerifies(): void
    {
        $clock                    = new MockClock(new DateTimeImmutable('2026-10-17T10:06:00Z'));
        $failed                   = new ScriptedListableTransport();
        $failed->messages['8813'] = new Envelope(new InvoiceMessage());
        $executor                 = new FailedMessageCommandExecutor(RemoteRetryFixtures::credentials(), new ServiceLocator(['failed' => static fn (): ScriptedListableTransport => $failed]), ['retry', 'discard'], ['failed'], null, $clock);
        $command                  = RemoteCommand::fromArray($this->fixture('claim_response_discard.json')['commands'][0]);
        self::assertNotNull($command);

        self::assertSame('done (removed)', $executor->execute($command)->summary());
        self::assertCount(1, $failed->rejected);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(RemoteRetryFixtures::CONTRACT_DIR . '/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }
}
