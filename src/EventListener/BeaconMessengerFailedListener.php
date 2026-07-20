<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

use function is_string;

/**
 * Reports Messenger worker failures that will not be retried.
 */
final class BeaconMessengerFailedListener
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        /** @var list<class-string> */
        private readonly array $ignoreExceptions = [],
    ) {
    }

    /**
     * Capture the failure when Messenger will not retry the message.
     */
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if (!$this->enabled || !$this->client->isEnabled()) {
            return;
        }

        if ($event->willRetry()) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($this->shouldIgnore($throwable)) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message  = $envelope->getMessage();

        $this->client->captureException($throwable, [
            'messenger' => [
                'message_class' => $message::class,
                'receiver_name' => $event->getReceiverName(),
            ],
        ]);
    }

    /**
     * Whether `$throwable` matches any configured ignore class.
     */
    private function shouldIgnore(Throwable $throwable): bool
    {
        foreach ($this->ignoreExceptions as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            if ($throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
