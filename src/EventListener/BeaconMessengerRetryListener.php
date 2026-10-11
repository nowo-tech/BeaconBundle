<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Closure;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Messenger\MessengerContext;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sprintf;

/**
 * Opt-in visibility for Messenger retries (`messenger.report_retries` / `messenger.report_recovered`).
 *
 * - A failure that Messenger **will retry** is sent as a `warning` event with
 *   `extra.messenger.retry_state = will_retry`, grouped per message class + handler + root exception type.
 * - A message handled successfully **after** at least one retry is sent as an `info` event with
 *   `retry_state = recovered`, grouped per message class + handler.
 *
 * Final failures stay with {@see BeaconMessengerFailedListener}. Message bodies are never attached.
 */
final class BeaconMessengerRetryListener implements EventSubscriberInterface
{
    private readonly float $sampleRate;

    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param list<class-string> $ignoreExceptions
     * @param (Closure(): float)|null $random Returns a float in [0, 1); injectable for tests
     */
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $reportRetries = true,
        private readonly bool $reportRecovered = false,
        float $sampleRate = 1.0,
        private readonly array $ignoreExceptions = [],
        private readonly ?TraceIdProvider $traceIdProvider = null,
        ?Closure $random = null,
    ) {
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
        $this->random     = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class  => 'onMessageFailed',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
        ];
    }

    /**
     * Report a failure that Messenger is going to retry.
     */
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->reportRetries || !$event->willRetry() || !$this->client->isEnabled()) {
            return;
        }

        $throwable = $event->getThrowable();
        if (MessengerContext::isIgnored($throwable, $this->ignoreExceptions) || !$this->sampled()) {
            return;
        }

        $envelope = $event->getEnvelope();
        MessengerContext::restoreTrace($envelope, $this->traceIdProvider);

        $handlers  = MessengerContext::failedHandlers($throwable);
        $messenger = MessengerContext::withHandlers(
            MessengerContext::fromEnvelope($envelope, $event->getReceiverName()),
            $handlers,
        );
        $messenger['retry_state'] = MessengerContext::RETRY_STATE_WILL_RETRY;
        $messenger['attempt']     = ((int) $messenger['retry_count']) + 1;

        $root = MessengerContext::rootCause($throwable);

        $this->client->captureMessage(
            sprintf('Messenger retry: %s (%s)', $root->getMessage(), $root::class),
            'warning',
            [
                'messenger' => $messenger,
                'exception' => ['type' => $root::class, 'value' => $root->getMessage()],
            ],
            [
                'beacon.messenger.retry',
                (string) $messenger['message_class'],
                $handlers[0] ?? '',
                $root::class,
            ],
        );
    }

    /**
     * Report a message that succeeded after at least one retry.
     */
    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if (!$this->reportRecovered || !$this->client->isEnabled()) {
            return;
        }

        $envelope  = $event->getEnvelope();
        $messenger = MessengerContext::fromEnvelope($envelope, $event->getReceiverName());
        if (((int) $messenger['retry_count']) < 1 || !$this->sampled()) {
            return;
        }

        MessengerContext::restoreTrace($envelope, $this->traceIdProvider);

        $handlers                 = MessengerContext::handledHandlers($envelope);
        $messenger                = MessengerContext::withHandlers($messenger, $handlers);
        $messenger['retry_state'] = MessengerContext::RETRY_STATE_RECOVERED;

        $this->client->captureMessage(
            sprintf('Messenger message recovered after %d retries: %s', (int) $messenger['retry_count'], $messenger['message_class']),
            'info',
            ['messenger' => $messenger],
            [
                'beacon.messenger.recovered',
                (string) $messenger['message_class'],
                $handlers[0] ?? '',
            ],
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
