<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Messenger;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommand;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;

use function array_replace;
use function assert;
use function hash_hmac;
use function implode;

/**
 * Shared values for remote Messenger command tests (fake key, project, signed commands).
 */
final class RemoteRetryFixtures
{
    public const WORKER_KEY = 'bwk_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    public const PROJECT = '019fea2d-507b-7890-8b33-ca488db6f696';

    public const DSN = 'https://pub:secret@beacon.example.com:9447/' . self::PROJECT;

    public const CONTRACT_DIR = __DIR__ . '/../../Contract/fixtures/messenger_commands';

    public static function credentials(string $dsn = self::DSN, mixed $key = self::WORKER_KEY): RemoteRetryCredentials
    {
        return new RemoteRetryCredentials(new BeaconDsnParser(), $dsn, $key);
    }

    /**
     * Command data signed with the fixture key (overrides are applied before signing unless `$sign` is false).
     *
     * @param array<string, int|string> $overrides
     *
     * @return array<string, int|string>
     */
    public static function commandData(array $overrides = [], bool $sign = true): array
    {
        $data = array_replace([
            'id'                => '0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f6',
            'project'           => self::PROJECT,
            'action'            => RemoteCommand::ACTION_RETRY,
            'failure_transport' => 'failed',
            'message_id'        => '8812',
            'issued_at'         => '2026-10-17T10:02:11Z',
            'expires_at'        => '2026-10-17T10:17:11Z',
            'claim_token'       => 'claim-token-1',
        ], $overrides);

        if ($sign) {
            $data['signature'] = 'v1=' . hash_hmac('sha256', implode("\n", [
                'v1', $data['id'], $data['project'], $data['action'], $data['failure_transport'],
                $data['message_id'], $data['issued_at'], $data['expires_at'],
            ]), (new WorkerKey(self::WORKER_KEY))->signingKey());
        }

        return $data;
    }

    /**
     * @param array<string, int|string> $overrides
     */
    public static function command(array $overrides = [], bool $sign = true): RemoteCommand
    {
        $command = RemoteCommand::fromArray(self::commandData($overrides, $sign));
        assert($command instanceof RemoteCommand);

        return $command;
    }
}
