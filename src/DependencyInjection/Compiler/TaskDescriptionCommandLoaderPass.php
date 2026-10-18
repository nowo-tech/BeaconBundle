<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection\Compiler;

use Nowo\BeaconBundle\Scheduler\TaskDescriptionResolver;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;

/**
 * Gives {@see TaskDescriptionResolver} lazy access to the console command loader, so a
 * `RunCommandMessage` task is described by its command description.
 *
 * `console.command_loader` is registered by FrameworkBundle's `AddConsoleCommandPass` in the
 * "before removing" phase, after reference validation: this pass runs right after it (same phase,
 * lower priority). The loader is wrapped in a service closure (the command loader references the
 * catalog command, which needs the resolver).
 */
final class TaskDescriptionCommandLoaderPass implements CompilerPassInterface
{
    public const LOADER_ID = 'console.command_loader';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TaskDescriptionResolver::class) || !$container->has(self::LOADER_ID)) {
            return;
        }

        $definition = $container->getDefinition(TaskDescriptionResolver::class);
        // Named arguments are already resolved to positions in this phase (`$commandLoader` is #1).
        $key = array_key_exists('$commandLoader', $definition->getArguments()) ? '$commandLoader' : 1;
        $definition->setArgument($key, new ServiceClosureArgument(new Reference(self::LOADER_ID)));
    }
}
