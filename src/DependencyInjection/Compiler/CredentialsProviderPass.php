<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsProviderInterface;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsStoreInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function interface_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Checks `nowo_beacon.credentials_provider` at compile time (a missing or wrong service would otherwise fail on the
 * first report) and hands a writable provider to `nowo:beacon:credentials:rotate`.
 */
final class CredentialsProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo.beacon.credentials_provider')) {
            return;
        }

        $id = $container->getParameter('nowo.beacon.credentials_provider');
        if (!is_string($id)) {
            return;
        }

        if (!$container->has($id)) {
            throw new LogicException(sprintf('nowo_beacon.credentials_provider: service "%s" does not exist.', $id));
        }

        $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());
        if (!is_string($class) || (!class_exists($class) && !interface_exists($class))) {
            // Factory-built or abstract definition: the constructor type of the resolver still enforces the port.
            return;
        }

        if (!is_a($class, BeaconCredentialsProviderInterface::class, true)) {
            throw new LogicException(sprintf('nowo_beacon.credentials_provider: service "%s" (%s) must implement %s.', $id, $class, BeaconCredentialsProviderInterface::class));
        }

        if (is_a($class, BeaconCredentialsStoreInterface::class, true) && $container->hasDefinition(RotateCredentialsCommand::class)) {
            $container->getDefinition(RotateCredentialsCommand::class)->setArgument('$store', new Reference($id));
        }
    }
}
