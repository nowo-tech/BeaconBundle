<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection;

use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
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
 */
final class NowoBeaconExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $dsn        = is_string($config['dsn'] ?? null) ? trim($config['dsn']) : '';
        $enabled    = (bool) $config['enabled'] && $dsn !== '';
        $serverName = is_string($config['server_name'] ?? null) && $config['server_name'] !== ''
            ? $config['server_name']
            : (gethostname() ?: 'unknown');

        $container->setParameter('nowo.beacon.enabled', $enabled);
        $container->setParameter('nowo.beacon.dsn', $dsn);
        $container->setParameter('nowo.beacon.environment', $config['environment']);
        $container->setParameter('nowo.beacon.release', $config['release']);
        $container->setParameter('nowo.beacon.server_name', $serverName);
        $container->setParameter('nowo.beacon.verify_peer', (bool) $config['verify_peer']);
        $container->setParameter('nowo.beacon.timeout', (float) $config['timeout']);
        $container->setParameter('nowo.beacon.ignore_exceptions', $config['ignore_exceptions']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if (!$enabled) {
            $null = new Definition(NullBeaconClient::class);
            $null->setPublic(false);
            $container->setDefinition(NullBeaconClient::class, $null);
            $container->setAlias(BeaconClientInterface::class, NullBeaconClient::class);
            $container->removeDefinition(BeaconExceptionListener::class);

            return;
        }

        // Validate DSN early at compile time.
        (new BeaconDsnParser())->parse($dsn);

        $dsnDefinition = new Definition(BeaconDsn::class);
        $dsnDefinition->setFactory([new Reference(BeaconDsnParser::class), 'parse']);
        $dsnDefinition->setArguments([$dsn]);
        $dsnDefinition->setPublic(false);
        $container->setDefinition(BeaconDsn::class, $dsnDefinition);

        $builder = new Definition(EnvelopeBuilder::class, [
            '$environment' => $config['environment'],
            '$release'     => $config['release'],
            '$serverName'  => $serverName,
        ]);
        $builder->setAutowired(false);
        $builder->setPublic(false);
        $container->setDefinition(EnvelopeBuilder::class, $builder);

        $transport = new Definition(EnvelopeTransport::class, [
            '$httpClient' => new Reference('http_client'),
            '$dsn'        => new Reference(BeaconDsn::class),
            '$verifyPeer' => (bool) $config['verify_peer'],
            '$timeout'    => (float) $config['timeout'],
            '$logger'     => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        $transport->setAutowired(false);
        $transport->setPublic(false);
        $container->setDefinition(EnvelopeTransport::class, $transport);

        $client = new Definition(BeaconClient::class, [
            '$transport'       => new Reference(EnvelopeTransport::class),
            '$envelopeBuilder' => new Reference(EnvelopeBuilder::class),
            '$enabled'         => true,
        ]);
        $client->setAutowired(false);
        $client->setPublic(false);
        $container->setDefinition(BeaconClient::class, $client);
        $container->setAlias(BeaconClientInterface::class, BeaconClient::class);

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
}
