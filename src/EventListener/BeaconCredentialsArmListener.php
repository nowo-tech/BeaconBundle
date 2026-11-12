<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lets the credentials resolver read the credentials provider once the application runs: on the first request, console
 * command or Messenger worker start. Until then (kernel boot, container warm-up) only the configuration is used, so
 * boot-time errors are reported with `nowo_beacon.dsn` and never wait for a database.
 *
 * Registered only when `nowo_beacon.credentials_provider` is set.
 */
final class BeaconCredentialsArmListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly BeaconCredentialsResolver $resolver,
    ) {
    }

    /**
     * Early on every entry point (event names as strings: Console and Messenger are optional).
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request'                                           => ['arm', 4096],
            'console.command'                                          => ['arm', 4096],
            'Symfony\\Component\\Messenger\\Event\\WorkerStartedEvent' => ['arm', 4096],
        ];
    }

    public function arm(): void
    {
        $this->resolver->arm();
    }
}
