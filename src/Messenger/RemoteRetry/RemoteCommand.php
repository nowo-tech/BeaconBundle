<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use function hash_hmac;
use function implode;
use function is_int;
use function is_string;
use function preg_match;

/**
 * One signed command claimed from Beacon (`POST /api/{project}/messenger/commands/claim`).
 *
 * Signature: `v1=` + lowercase hex HMAC-SHA256 (key = {@see WorkerKey::signingKey()}) of
 * {@see canonicalString()}: `v1\n{id}\n{project}\n{action}\n{failure_transport}\n{message_id}\n{issued_at}\n{expires_at}`
 * (values exactly as received, no trailing newline).
 */
final class RemoteCommand
{
    public const ACTION_RETRY = 'retry';

    public const ACTION_DISCARD = 'discard';

    public const ACTIONS = [self::ACTION_RETRY, self::ACTION_DISCARD];

    public const SIGNATURE_VERSION = 'v1';

    /** Command ids are put in the result URL: UUIDs and similar tokens only. */
    private const ID_PATTERN = '/^[A-Za-z0-9-]{1,64}$/';

    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly string $action,
        public readonly string $failureTransport,
        public readonly string $messageId,
        public readonly string $issuedAt,
        public readonly string $expiresAt,
        public readonly string $claimToken,
        public readonly string $signature,
    ) {
    }

    /**
     * Build from one entry of the claim response; null when `id` or `claim_token` is unusable
     * (such an entry cannot even be answered). Other missing fields become `''` and fail the
     * signature check.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $id         = $data['id'] ?? null;
        $claimToken = $data['claim_token'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1 || !is_string($claimToken) || $claimToken === '') {
            return null;
        }

        return new self(
            $id,
            self::string($data, 'project'),
            self::string($data, 'action'),
            self::string($data, 'failure_transport'),
            self::string($data, 'message_id'),
            self::string($data, 'issued_at'),
            self::string($data, 'expires_at'),
            $claimToken,
            self::string($data, 'signature'),
        );
    }

    /**
     * String covered by the signature.
     */
    public function canonicalString(): string
    {
        return implode("\n", [
            self::SIGNATURE_VERSION,
            $this->id,
            $this->project,
            $this->action,
            $this->failureTransport,
            $this->messageId,
            $this->issuedAt,
            $this->expiresAt,
        ]);
    }

    /**
     * Expected `signature` value for `$signingKey`.
     */
    public function expectedSignature(string $signingKey): string
    {
        return self::SIGNATURE_VERSION . '=' . hash_hmac('sha256', $this->canonicalString(), $signingKey);
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : '';
    }
}
