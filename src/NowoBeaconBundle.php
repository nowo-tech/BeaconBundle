<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle;

use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony client for self-hosted Symfony Beacon (Envelope ingest).
 */
final class NowoBeaconBundle extends Bundle
{
    /**
     * Instantiates fatal / PHP-warning handlers (they register once per process).
     */
    public function boot(): void
    {
        $container = $this->container;
        if (!$container instanceof ContainerInterface) {
            return;
        }

        if ($container->has(BeaconFatalErrorHandler::class)) {
            $handler = $container->get(BeaconFatalErrorHandler::class);
            if ($handler instanceof BeaconFatalErrorHandler) {
                // @igor-ignore - Not shared worker service state.
                $handler->register();
            }
        }

        if ($container->has(BeaconPhpWarningHandler::class)) {
            $handler = $container->get(BeaconPhpWarningHandler::class);
            if ($handler instanceof BeaconPhpWarningHandler) {
                // @igor-ignore - Not shared worker service state.
                $handler->register();
            }
        }
    }
}
