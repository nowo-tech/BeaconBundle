<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\DeployCapableInterface;
use Nowo\BeaconBundle\Client\QueueSnapshotCapableInterface;
use Throwable;

/**
 * In-memory client that records every capture call (tests only).
 */
final class RecordingBeaconClient implements BeaconClientInterface, QueueSnapshotCapableInterface, DeployCapableInterface
{
    /** @var list<array{throwable: Throwable, extra: array<string, mixed>, fingerprint: list<string>|null}> */
    public array $exceptions = [];

    /** @var list<array{message: string, level: string, extra: array<string, mixed>, fingerprint: list<string>|null}> */
    public array $messages = [];

    /** @var list<array{name: string, start: float, end: float, spans: list<array<string, mixed>>, extra: array<string, mixed>}> */
    public array $transactions = [];

    /** @var list<list<array{name: string, count: int|null, failure: bool}>> */
    public array $snapshots = [];

    /** @var list<array<string, mixed>> */
    public array $deploys = [];

    public function __construct(
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): string
    {
        $this->exceptions[] = ['throwable' => $throwable, 'extra' => $extra, 'fingerprint' => $fingerprint];

        return 'exception-id';
    }

    public function captureMessage(string $message, string $level = 'error', array $extra = [], ?array $fingerprint = null): string
    {
        $this->messages[] = ['message' => $message, 'level' => $level, 'extra' => $extra, 'fingerprint' => $fingerprint];

        return 'message-id';
    }

    public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
    }

    public function captureTransaction(
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): string {
        $this->transactions[] = [
            'name'  => $transactionName,
            'start' => $startTimestamp,
            'end'   => $endTimestamp,
            'spans' => $spans,
            'extra' => $extra,
        ];

        return 'transaction-id';
    }

    public function setTag(string $key, mixed $value): void
    {
    }

    public function setTags(array $tags): void
    {
    }

    public function getTags(): array
    {
        return [];
    }

    public function clearTags(): void
    {
    }

    public function captureQueueSnapshot(array $transports): string
    {
        $this->snapshots[] = $transports;

        return 'snapshot-id';
    }

    public function captureDeploy(array $deploy): string
    {
        $this->deploys[] = $deploy;

        return 'deploy-id';
    }
}
