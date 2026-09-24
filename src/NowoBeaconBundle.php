<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle;

use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony client for self-hosted Symfony Beacon (Envelope ingest).
 */
final class NowoBeaconBundle extends Bundle
{
    /**
     * Instantiates the fatal error handler (it registers its shutdown function once per process).
     */
    public function boot(): void
    {
        $container = $this->container;
        if (!$container instanceof ContainerInterface || !$container->has(BeaconFatalErrorHandler::class)) {
            return;
        }

        $handler = $container->get(BeaconFatalErrorHandler::class);
        if ($handler instanceof BeaconFatalErrorHandler) {
            $handler->register();
        }
    }
}
