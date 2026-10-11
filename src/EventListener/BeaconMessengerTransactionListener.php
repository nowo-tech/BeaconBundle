<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Closure;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Messenger\MessengerContext;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function max;
use function microtime;
use function min;
use function mt_getrandmax;
use function mt_rand;

/**
 * Sends one performance transaction per Messenger message handled by a worker (opt-in via
 * `messenger.transactions`).
 *
 * - Name: message class; `extra.messenger.op = queue.process`, plus status (`ok` / `failed` / `retry`),
 *   handler(s), transport and retry count.
 * - Doctrine / HttpClient spans recorded while handling (when instrumentation is enabled) are attached,
 *   so N+1 detection also covers workers.
 *
 * The span buffer is cleared when a message is received so spans from a previous message (or from
 * worker idle time) never leak into the next transaction.
 */
final class BeaconMessengerTransactionListener implements EventSubscriberInterface, ResetInterface
{
    public const OP = 'queue.process';

    private ?float $startedAt = null;

    private readonly float $sampleRate;

    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param (Closure(): float)|null $random Returns a float in [0, 1); injectable for tests
     */
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly ?SpanBuffer $spanBuffer = null,
        float $sampleRate = 1.0,
        private readonly ?TraceIdProvider $traceIdProvider = null,
        ?Closure $random = null,
    ) {
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
        $this->random     = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * @return array<class-string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Run after other listeners may have decided to skip handling.
            WorkerMessageReceivedEvent::class => ['onMessageReceived', -1024],
            WorkerMessageHandledEvent::class  => ['onMessageHandled', -1024],
            // After BeaconMessengerFailedListener (priority 0): sending a transaction clears the
            // breadcrumb buffer, and the failure event should keep the breadcrumbs.
            WorkerMessageFailedEvent::class => ['onMessageFailed', -1024],
        ];
    }

    /**
     * Start timing and drop stale spans.
     */
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->reset();
        $this->spanBuffer?->drain();

        if (!$event->shouldHandle() || !$this->client->isEnabled() || !$this->sampled()) {
            return;
        }

        MessengerContext::restoreTrace($event->getEnvelope(), $this->traceIdProvider);
        $this->startedAt = microtime(true);
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $messenger = MessengerContext::withHandlers(
            MessengerContext::fromEnvelope($event->getEnvelope(), $event->getReceiverName()),
            MessengerContext::handledHandlers($event->getEnvelope()),
        );

        $this->finish($messenger, 'ok');
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $messenger = MessengerContext::withHandlers(
            MessengerContext::fromEnvelope($event->getEnvelope(), $event->getReceiverName()),
            MessengerContext::failedHandlers($event->getThrowable()),
        );

        $this->finish($messenger, $event->willRetry() ? 'retry' : 'failed');
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->startedAt = null;
    }

    /**
     * @param array<string, mixed> $messenger
     */
    private function finish(array $messenger, string $status): void
    {
        if ($this->startedAt === null) {
            return;
        }

        $start = $this->startedAt;
        $this->reset();

        $messenger['op']     = self::OP;
        $messenger['status'] = $status;

        $this->client->captureTransaction(
            (string) $messenger['message_class'],
            $start,
            microtime(true),
            [],
            ['messenger' => $messenger],
        );
    }

    private function sampled(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }

        return ($this->random)() < $this->sampleRate;
    }
}
