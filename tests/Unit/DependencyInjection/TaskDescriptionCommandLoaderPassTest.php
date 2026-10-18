<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\DependencyInjection;

use Nowo\BeaconBundle\DependencyInjection\Compiler\TaskDescriptionCommandLoaderPass;
use Nowo\BeaconBundle\NowoBeaconBundle;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\TaskDescriptionResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class TaskDescriptionCommandLoaderPassTest extends TestCase
{
    public function testLoaderRegisteredLateIsInjectedLazily(): void
    {
        $container = new ContainerBuilder();
        (new NowoBeaconBundle())->build($container);
        $container->setDefinition(CheckInPolicy::class, new Definition(CheckInPolicy::class));
        $container->setDefinition(TaskDescriptionResolver::class, (new Definition(TaskDescriptionResolver::class, [
            '$policy'        => new Reference(CheckInPolicy::class),
            '$commandLoader' => null,
        ]))->setPublic(true));

        // Like FrameworkBundle's AddConsoleCommandPass: the loader appears in the "before removing" phase.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setDefinition(TaskDescriptionCommandLoaderPass::LOADER_ID, new Definition(FactoryCommandLoader::class, [[
                    'app:x' => new ServiceClosureArgument(new Reference('app.command')),
                ]]));
                $container->setDefinition('app.command', (new Definition(Command::class, ['app:x']))->addMethodCall('setDescription', ['Does X.']));
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);

        $container->compile(true);

        $resolver = $container->get(TaskDescriptionResolver::class);
        self::assertInstanceOf(TaskDescriptionResolver::class, $resolver);
        self::assertSame('Does X.', $resolver->describe(new RunCommandMessage('app:x --y')));
    }

    public function testNoOpWithoutResolverOrLoader(): void
    {
        $container = new ContainerBuilder();
        (new TaskDescriptionCommandLoaderPass())->process($container);

        $container->setDefinition(TaskDescriptionResolver::class, new Definition(TaskDescriptionResolver::class, ['$commandLoader' => null]));
        (new TaskDescriptionCommandLoaderPass())->process($container);

        self::assertNull($container->getDefinition(TaskDescriptionResolver::class)->getArgument('$commandLoader'));

        $container->setDefinition(TaskDescriptionCommandLoaderPass::LOADER_ID, new Definition(FactoryCommandLoader::class, [[]]));
        (new TaskDescriptionCommandLoaderPass())->process($container);
        self::assertEquals(
            new ServiceClosureArgument(new Reference(TaskDescriptionCommandLoaderPass::LOADER_ID)),
            $container->getDefinition(TaskDescriptionResolver::class)->getArgument('$commandLoader'),
            'named argument kept when the pass runs before named arguments are resolved',
        );
    }
}
