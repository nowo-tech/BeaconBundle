<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Support\ConsoleInputSnapshot;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function is_string;

use const PHP_SAPI;

/**
 * Reports uncaught console command errors to Beacon (optional).
 *
 * Extra shape (no raw argv): command, command_class, exit_code, php_sapi,
 * verbosity, cwd, interactive, redacted arguments/options, missing_arguments.
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

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::ERROR => ['onConsoleError', 0]];
    }

    /**
     * Capture the console error unless ignored or the client is disabled.
     */
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
        $console = [
            'command'   => $command?->getName(),
            'exit_code' => $event->getExitCode(),
            'php_sapi'  => PHP_SAPI,
            ...ConsoleInputSnapshot::runtime($command, $event->getOutput()),
            ...ConsoleInputSnapshot::from($event->getInput(), $command),
        ];

        $this->client->captureException($error, [
            'console' => $console,
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
