<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle;

use Nowo\BeaconBundle\DependencyInjection\Compiler\RecordingFailureSenderPass;
use Nowo\BeaconBundle\DependencyInjection\Compiler\ScheduleProviderLocatorPass;
use Nowo\BeaconBundle\DependencyInjection\Compiler\TaskDescriptionCommandLoaderPass;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony client for self-hosted Symfony Beacon (Envelope ingest).
 */
final class NowoBeaconBundle extends Bundle
{
    /**
     * Registers the Scheduler check-in provider locator pass (after Symfony's scheduler pass) and the
     * failure-sender recording pass (failure-transport ids in `extra.messenger`) and the command
     * loader pass of the Scheduler task descriptions.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ScheduleProviderLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
        $container->addCompilerPass(new RecordingFailureSenderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
        // After FrameworkBundle's AddConsoleCommandPass (before removing, priority 0) registers console.command_loader.
        $container->addCompilerPass(new TaskDescriptionCommandLoaderPass(), PassConfig::TYPE_BEFORE_REMOVING, -16);
    }

    /**
     * Instantiates fatal / PHP-warning handlers and makes them the active ones (one process-wide hook each).
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

    /**
     * Releases the handlers this kernel instantiated, so a process that boots many kernels (PHPUnit) frees each
     * kernel's container.
     */
    public function shutdown(): void
    {
        $container = $this->container;
        if (!$container instanceof ContainerInterface) {
            return;
        }

        foreach ([BeaconFatalErrorHandler::class, BeaconPhpWarningHandler::class] as $id) {
            if (!$container->initialized($id)) {
                continue;
            }

            $handler = $container->get($id);
            if ($handler instanceof BeaconFatalErrorHandler || $handler instanceof BeaconPhpWarningHandler) {
                $handler->unregister();
            }
        }
    }
}
