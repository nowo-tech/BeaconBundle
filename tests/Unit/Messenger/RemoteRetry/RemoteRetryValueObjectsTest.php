<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Messenger\RemoteRetry;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\BeaconBundle\Messenger\RemoteRetry\CommandResult;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommand;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function print_r;
use function str_repeat;
use function strlen;
use function var_export;

final class RemoteRetryValueObjectsTest extends TestCase
{
    public function testWorkerKeyValidationAndSecrecy(): void
    {
        self::assertTrue(WorkerKey::isValid('  ' . RemoteRetryFixtures::WORKER_KEY . "\n"));
        self::assertFalse(WorkerKey::isValid('bwk_short'));
        self::assertFalse(WorkerKey::isValid('pk_' . str_repeat('a', 43)));

        $key = new WorkerKey(' ' . RemoteRetryFixtures::WORKER_KEY . ' ');
        self::assertSame('Bearer ' . RemoteRetryFixtures::WORKER_KEY, $key->authorizationHeader());
        self::assertSame(32, strlen($key->signingKey()));
        self::assertNotSame($key->authKey(), $key->signingKey());
        self::assertStringNotContainsString(RemoteRetryFixtures::WORKER_KEY, print_r($key, true));
        self::assertSame(['key' => 'bwk_***'], $key->__debugInfo());

        try {
            new WorkerKey('bwk_short');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('bwk_short', $e->getMessage());
        }
    }

    public function testRemoteCommandFromArray(): void
    {
        self::assertNull(RemoteCommand::fromArray([]));
        self::assertNull(RemoteCommand::fromArray(['id' => str_repeat('a', 65), 'claim_token' => 't']));
        self::assertNull(RemoteCommand::fromArray(['id' => '../etc', 'claim_token' => 't']));
        self::assertNull(RemoteCommand::fromArray(['id' => 'ok', 'claim_token' => '']));

        $minimal = RemoteCommand::fromArray(['id' => 'ok', 'claim_token' => 't', 'message_id' => 42, 'action' => ['x']]);
        self::assertNotNull($minimal);
        self::assertSame('42', $minimal->messageId);
        self::assertSame('', $minimal->action);
        self::assertSame("v1\nok\n\n\n\n42\n\n", $minimal->canonicalString());
    }

    public function testCommandResultShapes(): void
    {
        $at     = new DateTimeImmutable('2026-10-17T12:02:41+02:00');
        $done   = CommandResult::done(CommandResult::OUTCOME_REMOVED, $at);
        $failed = CommandResult::failed(CommandResult::REASON_NOT_FOUND, $at);
        $class  = CommandResult::failed(CommandResult::REASON_REQUEUE_FAILED, $at, new RuntimeException('secret detail'));

        self::assertSame('2026-10-17T10:02:41Z', $done->executedAt);
        self::assertSame('done (removed)', $done->summary());
        self::assertSame('failed (not_found)', $failed->summary());
        self::assertSame(RuntimeException::class, $class->exceptionClass);
        self::assertStringNotContainsString('secret detail', var_export($class->toPayload('t', 's'), true));
        self::assertSame(str_repeat('w', 255), $done->toPayload('t', str_repeat('w', 300))['server_name']);
        self::assertContains(CommandResult::REASON_REMOVE_FAILED_AFTER_REQUEUE, CommandResult::REASONS);
        self::assertCount(10, CommandResult::REASONS);

        self::assertEquals($class, CommandResult::fromStored($class->toStored()));
        self::assertNull(CommandResult::fromStored('in_progress'));
        self::assertNull(CommandResult::fromStored(['status' => 'weird', 'executed_at' => 'x']));
        self::assertNull(CommandResult::fromStored(['status' => 'done', 'executed_at' => 5]));
        $loose = CommandResult::fromStored(['status' => 'done', 'executed_at' => 'x', 'outcome' => 7]);
        self::assertNotNull($loose);
        self::assertNull($loose->outcome);
    }

    public function testCredentialsProblems(): void
    {
        self::assertSame('BEACON_DSN is empty: remote Messenger commands need the Beacon DSN.', RemoteRetryFixtures::credentials('')->problem());
        self::assertStringStartsWith('Invalid Beacon DSN: ', (string) RemoteRetryFixtures::credentials('ftp://x')->problem());
        self::assertStringContainsString('worker_key is empty', (string) RemoteRetryFixtures::credentials(key: null)->problem());
        self::assertStringContainsString('Invalid Beacon worker key', (string) RemoteRetryFixtures::credentials(key: 'nope')->problem());
        self::assertFalse(RemoteRetryFixtures::credentials('')->matchesProject(RemoteRetryFixtures::PROJECT));
        self::assertNull(RemoteRetryFixtures::credentials('')->workerKey());

        $ok = RemoteRetryFixtures::credentials();
        self::assertNull($ok->problem());
        self::assertNotNull($ok->dsn());
        self::assertNotNull($ok->workerKey());
        self::assertTrue($ok->matchesProject(strtoupper(RemoteRetryFixtures::PROJECT)));
        self::assertFalse($ok->matchesProject('019fea2d-507b-7890-8b33-000000000000'));
    }
}
