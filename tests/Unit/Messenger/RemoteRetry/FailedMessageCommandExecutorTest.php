<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Messenger\RemoteRetry;

use DateTimeImmutable;
use Nowo\BeaconBundle\Messenger\BeaconTraceStamp;
use Nowo\BeaconBundle\Messenger\RemoteRetry\CommandResult;
use Nowo\BeaconBundle\Messenger\RemoteRetry\FailedMessageCommandExecutor;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommand;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\DoctrineTransports;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\InvoiceMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\ScriptedListableTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function json_encode;

final class FailedMessageCommandExecutorTest extends TestCase
{
    private MockClock $clock;

    private ScriptedListableTransport $failed;

    private ScriptedListableTransport $async;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->logger                   = new ArrayLogger();
        $this->clock                    = new MockClock(new DateTimeImmutable('2026-10-17T10:03:00Z'));
        $this->failed                   = new ScriptedListableTransport();
        $this->async                    = new ScriptedListableTransport();
        $this->failed->messages['8812'] = new Envelope(new InvoiceMessage(), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(3),
            new DelayStamp(0),
            new TransportMessageIdStamp('8812'),
            new ReceivedStamp('failed'),
            new BeaconTraceStamp('trace-1'),
            ErrorDetailsStamp::create(new RuntimeException('boom')),
        ]);
    }

    public function testRetryResendsToOriginalTransportWithoutFailureStampsThenRejects(): void
    {
        $result = $this->executor()->execute(RemoteRetryFixtures::command());

        self::assertSame(CommandResult::STATUS_DONE, $result->status);
        self::assertSame(CommandResult::OUTCOME_REQUEUED, $result->outcome);
        self::assertSame('2026-10-17T10:03:00Z', $result->executedAt);
        self::assertCount(1, $this->async->sent);
        $sent = $this->async->sent[0];
        self::assertNull($sent->last(SentToFailureTransportStamp::class));
        self::assertNull($sent->last(RedeliveryStamp::class));
        self::assertNull($sent->last(DelayStamp::class));
        self::assertNull($sent->last(TransportMessageIdStamp::class));
        self::assertNull($sent->last(ReceivedStamp::class));
        self::assertInstanceOf(BeaconTraceStamp::class, $sent->last(BeaconTraceStamp::class));
        self::assertInstanceOf(ErrorDetailsStamp::class, $sent->last(ErrorDetailsStamp::class));
        self::assertCount(1, $this->failed->rejected);
        self::assertSame([], $this->failed->sent, 'nothing is handled or re-sent to the failure transport');
    }

    public function testRetryWithRealDoctrineTransports(): void
    {
        $doctrine = new DoctrineTransports();
        $stored   = $doctrine->failed->send(new Envelope(new InvoiceMessage('INV-9'), [new SentToFailureTransportStamp('async'), new RedeliveryStamp(0)]));
        $id       = (string) $stored->last(TransportMessageIdStamp::class)?->getId();

        $executor = new FailedMessageCommandExecutor(
            RemoteRetryFixtures::credentials(),
            new ServiceLocator(['failed' => static fn (): DoctrineTransport => $doctrine->failed, 'async' => static fn (): DoctrineTransport => $doctrine->async]),
            ['retry', 'discard'],
            ['failed'],
            new ArrayAdapter(),
            $this->clock,
        );

        self::assertSame('done (requeued)', $executor->execute(RemoteRetryFixtures::command(['message_id' => $id]))->summary());
        self::assertSame(0, $doctrine->count('failed'));
        self::assertSame(1, $doctrine->count('async'));
        $requeued = [...$doctrine->async->all()][0];
        self::assertInstanceOf(InvoiceMessage::class, $requeued->getMessage());
        self::assertSame('INV-9', $requeued->getMessage()->number);
        self::assertNull($requeued->last(SentToFailureTransportStamp::class));

        $second = RemoteRetryFixtures::command(['id' => '0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f8', 'message_id' => $id]);
        self::assertSame('failed (not_found)', $executor->execute($second)->summary(), 'already retried elsewhere');

        $doctrine->failed->send(new Envelope(new InvoiceMessage('INV-10'), [new SentToFailureTransportStamp('async')]));
        $discardId = (string) [...$doctrine->failed->all()][0]->last(TransportMessageIdStamp::class)?->getId();
        $discard   = RemoteRetryFixtures::command(['id' => '0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f9', 'action' => 'discard', 'message_id' => $discardId]);
        self::assertSame('done (removed)', $executor->execute($discard)->summary());
        self::assertSame(0, $doctrine->count('failed'));
        self::assertSame(1, $doctrine->count('async'));
    }

    /**
     * @return iterable<string, array{array<string, string>, bool, string}>
     */
    public static function refusals(): iterable
    {
        yield 'tampered message id' => [['message_id' => '9999'], false, CommandResult::REASON_SIGNATURE_INVALID];
        yield 'other project' => [['project' => '019fea2d-507b-7890-8b33-000000000000'], true, CommandResult::REASON_SIGNATURE_INVALID];
        yield 'expired beyond skew' => [['expires_at' => '2026-10-17T10:01:59Z'], true, CommandResult::REASON_EXPIRED];
        yield 'unparseable expiry' => [['expires_at' => 'tomorrow-ish?'], true, CommandResult::REASON_EXPIRED];
        yield 'unknown action' => [['action' => 'purge'], true, CommandResult::REASON_ACTION_NOT_ALLOWED];
        yield 'discard not allowed' => [['action' => 'discard'], true, CommandResult::REASON_ACTION_NOT_ALLOWED];
        yield 'transport not allowed' => [['failure_transport' => 'failed_high'], true, CommandResult::REASON_TRANSPORT_NOT_ALLOWED];
        yield 'transport not listable' => [['failure_transport' => 'amqp_failed'], true, CommandResult::REASON_TRANSPORT_NOT_LISTABLE];
        yield 'transport missing' => [['failure_transport' => 'ghost'], true, CommandResult::REASON_TRANSPORT_NOT_LISTABLE];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('refusals')]
    public function testRefusals(array $overrides, bool $resign, string $reason): void
    {
        $data = RemoteRetryFixtures::commandData();
        if ($resign) {
            $data = RemoteRetryFixtures::commandData($overrides);
        } else {
            $data = [...$data, ...$overrides];
        }
        $command = RemoteCommand::fromArray($data);
        self::assertNotNull($command);

        $result = $this->executor(['retry'], ['failed', 'amqp_failed', 'ghost'])->execute($command);

        self::assertSame(CommandResult::STATUS_FAILED, $result->status);
        self::assertSame($reason, $result->reasonCode);
        self::assertSame([], $this->async->sent);
        self::assertSame([], $this->failed->rejected);
        self::assertStringContainsString($reason, $this->logger->messages()[0]);
    }

    public function testSkewToleranceAcceptsJustExpiredCommandsAndUppercaseSignature(): void
    {
        $data              = RemoteRetryFixtures::commandData(['expires_at' => '2026-10-17T10:02:30Z']);
        $data['signature'] = strtoupper((string) $data['signature']);
        $command           = RemoteCommand::fromArray($data);
        self::assertNotNull($command);

        self::assertSame('done (requeued)', $this->executor()->execute($command)->summary());
    }

    public function testCredentialProblemIsAnInternalError(): void
    {
        $executor = new FailedMessageCommandExecutor(RemoteRetryFixtures::credentials(key: ''), $this->locator(), ['retry'], ['failed']);

        self::assertSame('failed (internal_error)', $executor->execute(RemoteRetryFixtures::command())->summary());
    }

    public function testLegacyNumericDsnSkipsTheProjectComparison(): void
    {
        $credentials = RemoteRetryFixtures::credentials('https://pub:secret@beacon.example.com/7');
        $executor    = new FailedMessageCommandExecutor($credentials, $this->locator(), ['retry'], ['failed'], null, $this->clock);

        self::assertSame('done (requeued)', $executor->execute(RemoteRetryFixtures::command())->summary());
        self::assertFalse($credentials->matchesProject(''));
    }

    public function testNotFoundCases(): void
    {
        $executor = $this->executor();
        self::assertSame('failed (not_found)', $executor->execute(RemoteRetryFixtures::command(['message_id' => '1']))->summary());

        $this->failed->messages['2'] = new Envelope(new InvoiceMessage()); // no SentToFailureTransportStamp
        self::assertSame('failed (not_found)', $executor->execute(RemoteRetryFixtures::command(['id' => 'cmd-2', 'message_id' => '2']))->summary());

        self::assertSame('failed (not_found)', $executor->execute(RemoteRetryFixtures::command(['id' => 'cmd-3', 'action' => 'discard', 'message_id' => '3']))->summary());
    }

    public function testRetryFailureReasons(): void
    {
        $executor = $this->executor();

        $this->failed->messages['5'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async'), new MessageDecodingFailedStamp()]);
        self::assertSame('failed (requeue_failed)', $executor->execute(RemoteRetryFixtures::command(['id' => 'c5', 'message_id' => '5']))->summary());

        $this->failed->messages['6'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('vanished')]);
        self::assertSame('failed (requeue_failed)', $executor->execute(RemoteRetryFixtures::command(['id' => 'c6', 'message_id' => '6']))->summary());

        $this->async->failSend = true;
        self::assertSame('failed (requeue_failed, RuntimeException)', $executor->execute(RemoteRetryFixtures::command(['id' => 'c7']))->summary());
        self::assertSame([], $this->failed->rejected);

        $this->async->failSend    = false;
        $this->failed->failReject = true;
        self::assertSame('failed (remove_failed_after_requeue, RuntimeException)', $executor->execute(RemoteRetryFixtures::command(['id' => 'c8']))->summary());
        self::assertCount(1, $this->async->sent);

        self::assertSame('failed (remove_failed, RuntimeException)', $executor->execute(RemoteRetryFixtures::command(['id' => 'c9', 'action' => 'discard']))->summary());

        $this->failed->failFind = true;
        self::assertSame('failed (internal_error, RuntimeException)', $executor->execute(RemoteRetryFixtures::command(['id' => 'c10']))->summary());
        self::assertSame('failed (internal_error, RuntimeException)', $executor->execute(RemoteRetryFixtures::command(['id' => 'c11', 'action' => 'discard']))->summary());
    }

    public function testExecutedIdsAreNeverExecutedTwice(): void
    {
        $cache    = new ArrayAdapter();
        $executor = $this->executor(cache: $cache);
        $first    = $executor->execute(RemoteRetryFixtures::command());
        $replay   = $executor->execute(RemoteRetryFixtures::command(['claim_token' => 'another-claim']));

        self::assertSame($first->toStored(), $replay->toStored());
        self::assertCount(1, $this->async->sent);

        // A fresh executor (another worker process on the same host) sees the shared cache.
        $other = $this->executor(cache: $cache);
        self::assertSame($first->toStored(), $other->execute(RemoteRetryFixtures::command())->toStored());
        self::assertCount(1, $this->async->sent);
    }

    public function testCommandStartedByACrashedProcessIsNotRerun(): void
    {
        $cache = new ArrayAdapter();
        $item  = $cache->getItem('nowo_beacon.remote_command.' . hash('sha256', '0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f6'));
        $cache->save($item->set('in_progress'));

        self::assertSame('failed (internal_error)', $this->executor(cache: $cache)->execute(RemoteRetryFixtures::command())->summary());
        self::assertSame([], $this->async->sent);
    }

    public function testInProcessFallbackWithoutOrWithBrokenCache(): void
    {
        $executor = $this->executor();
        $executor->execute(RemoteRetryFixtures::command());
        $executor->execute(RemoteRetryFixtures::command());
        self::assertCount(1, $this->async->sent);

        $broken = $this->createMock(CacheItemPoolInterface::class);
        $broken->method('getItem')->willThrowException(new InvalidArgumentException('down'));
        $executor = $this->executor(cache: $broken);
        $executor->execute(RemoteRetryFixtures::command(['id' => 'b1']));
        $executor->execute(RemoteRetryFixtures::command(['id' => 'b1']));
        self::assertCount(2, $this->async->sent);
        self::assertStringContainsString('cache unavailable', implode("\n", $this->logger->messages()));

        // Entries expire after 24 h.
        $this->clock->modify('+25 hours');
        $this->failed->messages['8812'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);
        $fresh                          = RemoteRetryFixtures::command(['id' => 'b1', 'expires_at' => '2026-10-18T12:00:00Z']);
        self::assertSame('done (requeued)', $executor->execute($fresh)->summary());
        self::assertCount(3, $this->async->sent);
    }

    public function testInProcessListIsBounded(): void
    {
        $executor = $this->executor();
        for ($i = 0; $i < 1001; ++$i) {
            $executor->execute(RemoteRetryFixtures::command(['id' => 'n' . $i, 'message_id' => 'missing']));
        }

        // The list was cleared once it reached its bound: the first id is unknown again.
        $this->failed->messages['missing'] = new Envelope(new InvoiceMessage(), [new SentToFailureTransportStamp('async')]);
        self::assertSame('done (requeued)', $executor->execute(RemoteRetryFixtures::command(['id' => 'n0', 'message_id' => 'missing']))->summary());
    }

    public function testDescribeFailureTransportsAndBrokenLocator(): void
    {
        $executor = $this->executor(['retry'], ['failed', 'amqp_failed', 'ghost']);
        self::assertSame([
            ['name' => 'failed', 'listable' => true],
            ['name' => 'amqp_failed', 'listable' => false],
            ['name' => 'ghost', 'listable' => false],
        ], $executor->describeFailureTransports());

        $broken = $this->createMock(ContainerInterface::class);
        $broken->method('has')->willReturn(true);
        $broken->method('get')->willThrowException(new RuntimeException('boom'));
        self::assertSame([['name' => 'failed', 'listable' => false]], (new FailedMessageCommandExecutor(RemoteRetryFixtures::credentials(), $broken, ['retry'], ['failed']))->describeFailureTransports());
        self::assertSame([['name' => 'failed', 'listable' => false]], (new FailedMessageCommandExecutor(RemoteRetryFixtures::credentials(), null, ['retry'], ['failed']))->describeFailureTransports());
        self::assertSame([['name' => '', 'listable' => false]], (new FailedMessageCommandExecutor(RemoteRetryFixtures::credentials(), $this->locator(), ['retry'], ['']))->describeFailureTransports());
    }

    public function testLogsNeverContainKeysOrClaimTokens(): void
    {
        $this->executor()->execute(RemoteRetryFixtures::command(['claim_token' => 'secret-claim-token']));
        $this->executor()->execute(RemoteRetryFixtures::command(['message_id' => '0'], false));

        $all = implode("\n", $this->logger->messages()) . json_encode($this->logger->records);
        self::assertStringNotContainsString('secret-claim-token', $all);
        self::assertStringNotContainsString(RemoteRetryFixtures::WORKER_KEY, $all);
        self::assertStringNotContainsString('INV-1', $all, 'message bodies never reach the logs');
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $transports
     */
    private function executor(array $allowed = ['retry', 'discard'], array $transports = ['failed'], ?CacheItemPoolInterface $cache = null, ?RemoteRetryCredentials $credentials = null): FailedMessageCommandExecutor
    {
        return new FailedMessageCommandExecutor($credentials ?? RemoteRetryFixtures::credentials(), $this->locator(), $allowed, $transports, $cache, $this->clock, $this->logger);
    }

    /**
     * @return ServiceLocator<mixed>
     */
    private function locator(): ServiceLocator
    {
        $failed = $this->failed;
        $async  = $this->async;

        return new ServiceLocator([
            'failed'      => static fn (): ScriptedListableTransport => $failed,
            'async'       => static fn (): ScriptedListableTransport => $async,
            'amqp_failed' => static fn (): InMemoryTransport => new InMemoryTransport(),
        ]);
    }
}
