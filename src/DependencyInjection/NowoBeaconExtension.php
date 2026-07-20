<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection;

use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;

/**
 * Loads `nowo_beacon` configuration and wires the HTTP Envelope client.
 *
 * DSN values coming from `%env(...)%` are resolved at runtime via {@see BeaconClientFactory}
 * so an empty `BEACON_DSN` disables reporting without failing container compilation.
 */
final class NowoBeaconExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $dsn         = is_string($config['dsn'] ?? null) ? trim($config['dsn']) : '';
        $enabledFlag = (bool) $config['enabled'];
        $serverName  = is_string($config['server_name'] ?? null) && $config['server_name'] !== ''
            ? $config['server_name']
            : (gethostname() ?: 'unknown');

        // Literal DSNs contain "://"; env placeholders / %env(...)% do not.
        $isLiteralDsn = str_contains($dsn, '://');

        $container->setParameter('nowo.beacon.enabled', $enabledFlag && ($dsn !== ''));
        $container->setParameter('nowo.beacon.dsn', $config['dsn'] ?? '');
        $container->setParameter('nowo.beacon.environment', $config['environment']);
        $container->setParameter('nowo.beacon.release', $config['release']);
        $container->setParameter('nowo.beacon.server_name', $serverName);
        $container->setParameter('nowo.beacon.verify_peer', (bool) $config['verify_peer']);
        $container->setParameter('nowo.beacon.timeout', (float) $config['timeout']);
        $container->setParameter('nowo.beacon.ignore_exceptions', $config['ignore_exceptions']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if (!$enabledFlag || $dsn === '') {
            $this->registerNullClient($container);

            return;
        }

        // Fail fast for literal DSNs; env / placeholder values are validated at runtime.
        if ($isLiteralDsn) {
            (new BeaconDsnParser())->parse($dsn);
            $container->setParameter('nowo.beacon.enabled', true);
        }

        $factory = new Definition(BeaconClientFactory::class, [
            '$parser'     => new Reference(BeaconDsnParser::class),
            '$httpClient' => new Reference('http_client'),
            '$logger'     => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        $factory->setAutowired(false);
        $factory->setPublic(false);
        $container->setDefinition(BeaconClientFactory::class, $factory);

        $client = new Definition(BeaconClientInterface::class);
        $client->setFactory([new Reference(BeaconClientFactory::class), 'create']);
        $client->setArguments([
            '$enabled'     => $enabledFlag,
            '$dsn'         => $config['dsn'] ?? '',
            '$environment' => $config['environment'],
            '$release'     => $config['release'],
            '$serverName'  => $serverName,
            '$verifyPeer'  => (bool) $config['verify_peer'],
            '$timeout'     => (float) $config['timeout'],
        ]);
        $client->setAutowired(false);
        $client->setPublic(false);
        $container->setDefinition('nowo.beacon.client', $client);
        $container->setAlias(BeaconClientInterface::class, 'nowo.beacon.client');

        if (!(bool) $config['register_error_listener']) {
            $container->removeDefinition(BeaconExceptionListener::class);

            return;
        }

        $listener = $container->getDefinition(BeaconExceptionListener::class);
        $listener->setArgument('$client', new Reference(BeaconClientInterface::class));
        $listener->setArgument('$enabled', true);
        $listener->setArgument('$ignoreExceptions', $config['ignore_exceptions']);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    private function registerNullClient(ContainerBuilder $container): void
    {
        $null = new Definition(NullBeaconClient::class);
        $null->setPublic(false);
        $container->setDefinition(NullBeaconClient::class, $null);
        $container->setAlias(BeaconClientInterface::class, NullBeaconClient::class);
        $container->setParameter('nowo.beacon.enabled', false);
        $container->removeDefinition(BeaconExceptionListener::class);
    }
}
