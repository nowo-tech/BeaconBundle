<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Messenger;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Listable transport whose operations can be told to fail (tests only).
 */
final class ScriptedListableTransport implements TransportInterface, ListableReceiverInterface
{
    /** @var array<int|string, Envelope> */
    public array $messages = [];

    /** @var list<Envelope> */
    public array $sent = [];

    /** @var list<Envelope> */
    public array $rejected = [];

    public bool $failFind = false;

    public bool $failSend = false;

    public bool $failReject = false;

    public mixed $sendId = null;

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
        if ($this->failReject) {
            throw new RuntimeException('reject failed');
        }
        $this->rejected[] = $envelope;
    }

    public function send(Envelope $envelope): Envelope
    {
        if ($this->failSend) {
            throw new RuntimeException('send failed');
        }
        $this->sent[] = $envelope;

        return $this->sendId !== null ? $envelope->with(new TransportMessageIdStamp($this->sendId)) : $envelope;
    }

    public function all(?int $limit = null): iterable
    {
        return array_values($this->messages);
    }

    public function find(mixed $id): ?Envelope
    {
        if ($this->failFind) {
            throw new RuntimeException('find failed');
        }

        return $this->messages[(string) $id] ?? null;
    }
}
