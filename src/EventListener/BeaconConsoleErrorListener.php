<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function is_string;

/**
 * Reports uncaught console command errors to Beacon (optional).
 */
final class BeaconConsoleErrorListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        /** @var list<class-string> */
        private readonly array $ignoreExceptions = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::ERROR => ['onConsoleError', 0]];
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        if (!$this->enabled || !$this->client->isEnabled()) {
            return;
        }

        $error = $event->getError();
        if ($this->shouldIgnore($error)) {
            return;
        }

        $command = $event->getCommand();
        $this->client->captureException($error, [
            'console' => true,
            'command' => $command?->getName(),
        ]);
    }

    private function shouldIgnore(\Throwable $throwable): bool
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
