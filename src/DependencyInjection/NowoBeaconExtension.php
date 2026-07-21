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
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessageHandler;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconRequestTransactionListener;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use Nowo\BeaconBundle\Instrumentation\DoctrineSqlMiddleware;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Instrumentation\TraceableBeaconHttpClient;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Nowo\BeaconBundle\Scope\Scope;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function interface_exists;
use function is_string;

/**
 * Loads `nowo_beacon` configuration and wires the HTTP Envelope client.
 *
 * DSN values coming from `%env(...)%` are resolved at runtime via {@see BeaconClientFactory}
 * so an empty `BEACON_DSN` disables reporting without failing container compilation.
 */
final class NowoBeaconExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Prepends MonologBundle handler config when `monolog_handler.enabled` is true.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('monolog')) {
            return;
        }

        $configs = $container->getExtensionConfig($this->getAlias());
        $config  = $this->processConfiguration(new Configuration(), $configs);
        $monolog = $config['monolog_handler'] ?? [];

        if (!(bool) ($monolog['enabled'] ?? false)) {
            return;
        }

        if (!class_exists(\Monolog\Handler\AbstractProcessingHandler::class)) {
            return;
        }

        // MonologBundle only wires handlers declared in monolog.handlers (the monolog.handler tag is not enough).
        $container->prependExtensionConfig('monolog', [
            'handlers' => [
                'nowo_beacon' => [
                    'type'  => 'service',
                    'id'    => BeaconMonologHandler::class,
                    'level' => (string) ($monolog['level'] ?? 'error'),
                ],
            ],
        ]);
    }

    /**
     * Registers Beacon services from processed `nowo_beacon` configuration.
     *
     * @param array<int, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $dsn         = is_string($config['dsn'] ?? null) ? trim($config['dsn']) : '';
        $enabledFlag = (bool) $config['enabled'];
        $serverName  = is_string($config['server_name'] ?? null) && $config['server_name'] !== ''
            ? $config['server_name']
            : (gethostname() ?: 'unknown');
        $send = $config['send'] ?? [];

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
        $transportMode = (string) ($config['transport']['mode'] ?? 'sync');
        $container->setParameter('nowo.beacon.transport.mode', $transportMode);

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
            '$requestStack'        => new Reference('request_stack', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$scope'               => new Reference(Scope::class),
            '$spanBuffer'          => new Reference(SpanBuffer::class),
            '$pendingRegistry'     => new Reference(PendingTransportRegistry::class),
            '$messageBus'          => new Reference('messenger.default_bus', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        $factory->setAutowired(false);
        $factory->setPublic(false);
        $container->setDefinition(BeaconClientFactory::class, $factory);

        $beforeSend = null;
        if (is_string($config['before_send'] ?? null) && $config['before_send'] !== '') {
            $beforeSend = new Reference($config['before_send']);
        }

        $client = new Definition(BeaconClientInterface::class);
        $client->setFactory([new Reference(BeaconClientFactory::class), 'create']);
        $client->setArguments([
            '$enabled'       => $enabledFlag,
            '$dsn'           => $config['dsn'] ?? '',
            '$environment'   => $config['environment'],
            '$release'       => $config['release'],
            '$serverName'    => $serverName,
            '$verifyPeer'    => (bool) $config['verify_peer'],
            '$timeout'       => (float) $config['timeout'],
            '$send'          => $send,
            '$beforeSend'    => $beforeSend,
            '$transportMode' => $transportMode,
        ]);
        $client->setAutowired(false);
        $client->setPublic(false);
        $container->setDefinition('nowo.beacon.client', $client);
        $container->setAlias(BeaconClientInterface::class, 'nowo.beacon.client');

        if ($transportMode === 'async' || $transportMode === 'messenger') {
            // messenger may fall back to async when the bus is missing
            $flush = new Definition(FlushPendingTransportsListener::class, [
                '$registry' => new Reference(PendingTransportRegistry::class),
            ]);
            $flush->addTag('kernel.event_subscriber');
            $flush->setPublic(false);
            $container->setDefinition(FlushPendingTransportsListener::class, $flush);
        }

        if ($transportMode === 'messenger' && interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $syncTransport = new Definition(\Nowo\BeaconBundle\Envelope\EnvelopeTransport::class);
            $syncTransport->setFactory([new Reference(BeaconClientFactory::class), 'createSyncTransport']);
            $syncTransport->setArguments([
                '$enabled'    => $enabledFlag,
                '$dsn'        => $config['dsn'] ?? '',
                '$verifyPeer' => (bool) $config['verify_peer'],
                '$timeout'    => (float) $config['timeout'],
            ]);
            $syncTransport->setPublic(false);
            $container->setDefinition('nowo.beacon.sync_transport', $syncTransport);

            $handler = new Definition(SendBeaconEnvelopeMessageHandler::class, [
                '$transport' => new Reference('nowo.beacon.sync_transport'),
            ]);
            $handler->addTag('messenger.message_handler');
            $handler->setPublic(false);
            $container->setDefinition(SendBeaconEnvelopeMessageHandler::class, $handler);
        }

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

        if ((bool) ($config['register_messenger_listener'] ?? true)
            && class_exists(\Symfony\Component\Messenger\Event\WorkerMessageFailedEvent::class)
        ) {
            $messenger = new Definition(BeaconMessengerFailedListener::class, [
                '$client'           => new Reference(BeaconClientInterface::class),
                '$enabled'          => true,
                '$ignoreExceptions' => $config['ignore_exceptions'],
            ]);
            $messenger->addTag('kernel.event_listener', [
                'event' => \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent::class,
            ]);
            $messenger->setPublic(false);
            $container->setDefinition(BeaconMessengerFailedListener::class, $messenger);
        }

        if ((bool) ($config['auto_http_transaction'] ?? false)) {
            $tx = new Definition(BeaconRequestTransactionListener::class, [
                '$client'  => new Reference(BeaconClientInterface::class),
                '$enabled' => true,
            ]);
            $tx->addTag('kernel.event_subscriber');
            $tx->addTag('kernel.reset', ['method' => 'reset']);
            $tx->setPublic(false);
            $container->setDefinition(BeaconRequestTransactionListener::class, $tx);
        }

        $this->registerInstrumentation($container, $config);

        $monolog = $config['monolog_handler'] ?? [];
        // Check Monolog before referencing BeaconMonologHandler — class_exists(BeaconMonologHandler)
        // would autoload a file that extends AbstractProcessingHandler and fatals without monolog.
        if ((bool) ($monolog['enabled'] ?? false) && class_exists(\Monolog\Handler\AbstractProcessingHandler::class)) {
            $handler = new Definition(BeaconMonologHandler::class, [
                '$client' => new Reference(BeaconClientInterface::class),
                '$level'  => (string) ($monolog['level'] ?? 'error'),
            ]);
            $handler->setPublic(false);
            $container->setDefinition(BeaconMonologHandler::class, $handler);
        }
    }

    /**
     * Registers opt-in Doctrine / HttpClient instrumentation when enabled.
     *
     * @param array<string, mixed> $config
     */
    private function registerInstrumentation(ContainerBuilder $container, array $config): void
    {
        $instrumentation = $config['instrumentation'] ?? [];

        if ((bool) ($instrumentation['doctrine'] ?? false)
            && interface_exists(\Doctrine\DBAL\Driver\Middleware::class)
        ) {
            $middleware = new Definition(DoctrineSqlMiddleware::class, [
                '$spanBuffer'       => new Reference(SpanBuffer::class),
                '$breadcrumbBuffer' => new Reference(BreadcrumbBuffer::class),
            ]);
            $middleware->addTag('doctrine.middleware');
            $middleware->setPublic(false);
            $container->setDefinition(DoctrineSqlMiddleware::class, $middleware);
        }

        if ((bool) ($instrumentation['http_client'] ?? false)) {
            $http = new Definition(TraceableBeaconHttpClient::class);
            $http->setAutowired(false);
            $http->setAutoconfigured(false);
            $http->setPublic(false);
            $http->setDecoratedService('http_client');
            $http->setArguments([
                '$client'           => new Reference(TraceableBeaconHttpClient::class . '.inner'),
                '$spanBuffer'       => new Reference(SpanBuffer::class),
                '$breadcrumbBuffer' => new Reference(BreadcrumbBuffer::class),
            ]);
            $container->setDefinition(TraceableBeaconHttpClient::class, $http);
        }
    }

    /**
     * Extension alias (`nowo_beacon`).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Wire a no-op client and remove automatic listeners.
     */
    private function registerNullClient(ContainerBuilder $container): void
    {
        $null = new Definition(NullBeaconClient::class);
        $null->setPublic(false);
        $container->setDefinition(NullBeaconClient::class, $null);
        $container->setAlias(BeaconClientInterface::class, NullBeaconClient::class);
        $container->setParameter('nowo.beacon.enabled', false);
        $container->removeDefinition(BeaconExceptionListener::class);
        $container->removeDefinition(BeaconConsoleErrorListener::class);
        if ($container->hasDefinition(BeaconMessengerFailedListener::class)) {
            $container->removeDefinition(BeaconMessengerFailedListener::class);
        }
        if ($container->hasDefinition(BeaconRequestTransactionListener::class)) {
            $container->removeDefinition(BeaconRequestTransactionListener::class);
        }
    }
}
