<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection\Compiler;

use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function ksort;

/**
 * Collects `scheduler.schedule_provider` services into a service locator keyed by schedule name
 * for {@see ScheduleCatalogBuilder} (runs after Symfony's `AddScheduleMessengerPass`).
 */
final class ScheduleProviderLocatorPass implements CompilerPassInterface
{
    public const TAG = 'scheduler.schedule_provider';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ScheduleCatalogBuilder::class)) {
            return;
        }

        $providers = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $tags) {
            foreach ($tags as $attributes) {
                $name = $attributes['name'] ?? null;
                if (is_string($name) && $name !== '' && !isset($providers[$name])) {
                    $providers[$name] = new Reference($serviceId);
                }
            }
        }
        ksort($providers);

        $container->getDefinition(ScheduleCatalogBuilder::class)
            ->setArgument('$providers', ServiceLocatorTagPass::register($container, $providers));
    }
}
