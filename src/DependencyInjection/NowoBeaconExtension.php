<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Context\SecurityUserContextProvider;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
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
        $send        = $config['send'] ?? [];

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
        $container->setParameter('nowo.beacon.send', $send);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $userProvider = new Definition(SecurityUserContextProvider::class, [
            '$tokenStorage' => new Reference(
                'security.token_storage',
                ContainerBuilder::NULL_ON_INVALID_REFERENCE,
            ),
        ]);
        $userProvider->setAutowired(false);
        $userProvider->setPublic(false);
        $container->setDefinition(SecurityUserContextProvider::class, $userProvider);
        $container->setAlias(UserContextProviderInterface::class, SecurityUserContextProvider::class);

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
            '$parser'              => new Reference(BeaconDsnParser::class),
            '$httpClient'          => new Reference('http_client'),
            '$logger'              => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$userContextProvider' => new Reference(UserContextProviderInterface::class),
            '$breadcrumbBuffer'    => new Reference(BreadcrumbBuffer::class),
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
            '$send'        => $send,
        ]);
        $client->setAutowired(false);
        $client->setPublic(false);
        $container->setDefinition('nowo.beacon.client', $client);
        $container->setAlias(BeaconClientInterface::class, 'nowo.beacon.client');

        if ((bool) $config['register_error_listener']) {
            $listener = $container->getDefinition(BeaconExceptionListener::class);
            $listener->setArgument('$client', new Reference(BeaconClientInterface::class));
            $listener->setArgument('$enabled', true);
            $listener->setArgument('$ignoreExceptions', $config['ignore_exceptions']);
            $listener->setArgument('$sendRequest', (bool) ($send['request'] ?? true));
        } else {
            $container->removeDefinition(BeaconExceptionListener::class);
        }

        if ((bool) ($config['register_console_listener'] ?? true)) {
            $console = $container->getDefinition(BeaconConsoleErrorListener::class);
            $console->setArgument('$client', new Reference(BeaconClientInterface::class));
            $console->setArgument('$enabled', true);
            $console->setArgument('$ignoreExceptions', $config['ignore_exceptions']);
        } else {
            $container->removeDefinition(BeaconConsoleErrorListener::class);
        }

        $monolog = $config['monolog_handler'] ?? [];
        // Check Monolog before referencing BeaconMonologHandler — class_exists(BeaconMonologHandler)
        // would autoload a file that extends AbstractProcessingHandler and fatals without monolog.
        if ((bool) ($monolog['enabled'] ?? false) && class_exists(\Monolog\Handler\AbstractProcessingHandler::class)) {
            $handler = new Definition(BeaconMonologHandler::class, [
                '$client' => new Reference(BeaconClientInterface::class),
                '$level'  => (string) ($monolog['level'] ?? 'error'),
            ]);
            $handler->setPublic(false);
            $handler->addTag('monolog.handler');
            $container->setDefinition(BeaconMonologHandler::class, $handler);
        }
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
        $container->removeDefinition(BeaconConsoleErrorListener::class);
    }
}
