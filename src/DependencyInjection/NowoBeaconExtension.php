<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection;

use Doctrine\DBAL\Driver\Middleware;
use LogicException;
use Monolog\Handler\AbstractProcessingHandler;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Bridge\InstanceSettings\BeaconSettingsSection;
use Nowo\BeaconBundle\Bridge\InstanceSettings\InstanceSettingsCredentialsListener;
use Nowo\BeaconBundle\Bridge\InstanceSettings\InstanceSettingsCredentialsStore;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\DeployCommand;
use Nowo\BeaconBundle\Command\MessengerCommandsCommand;
use Nowo\BeaconBundle\Command\MessengerReportCommand;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Command\SchedulerCatalogCommand;
use Nowo\BeaconBundle\Command\SelfTestCommand;
use Nowo\BeaconBundle\Command\TestConnectionCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Context\SecurityUserContextProvider;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Controller\SelfTestController;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\IngestHttpClientFactory;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessageHandler;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use Nowo\BeaconBundle\EventListener\BeaconCredentialsArmListener;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerRetryListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerTransactionListener;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use Nowo\BeaconBundle\EventListener\BeaconRequestTransactionListener;
use Nowo\BeaconBundle\EventListener\BeaconSchedulerCheckInListener;
use Nowo\BeaconBundle\EventListener\BeaconTraceRequestListener;
use Nowo\BeaconBundle\EventListener\BeaconWorkerMemoryListener;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use Nowo\BeaconBundle\Ingest\IngestPathResolver;
use Nowo\BeaconBundle\Instrumentation\DoctrineSqlMiddleware;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Instrumentation\TraceableBeaconHttpClient;
use Nowo\BeaconBundle\Messenger\BeaconTraceMiddleware;
use Nowo\BeaconBundle\Messenger\FailureTransport\FailureTransportRecorder;
use Nowo\BeaconBundle\Messenger\RemoteRetry\FailedMessageCommandExecutor;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandPoller;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Nowo\BeaconBundle\Routing\BeaconRouteLoader;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Nowo\BeaconBundle\Scheduler\SchedulerRunRegistry;
use Nowo\BeaconBundle\Scheduler\TaskDescriptionResolver;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailHandler;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_map;
use function array_unique;
use function array_values;
use function class_exists;
use function interface_exists;
use function is_array;
use function is_string;
use function rtrim;
use function strtolower;

/**
 * Loads `nowo_beacon` configuration and wires the HTTP Envelope client.
 *
 * DSN values coming from `%env(...)%` are resolved at runtime via {@see BeaconClientFactory}
 * so an empty `BEACON_DSN` disables reporting without failing container compilation.
 */
final class NowoBeaconExtension extends Extension implements PrependExtensionInterface
{
    /** Service id of the InstanceSettings credentials store (`instance_settings_bridge: true`). */
    public const INSTANCE_SETTINGS_STORE = 'nowo_beacon.credentials_provider.instance_settings';

    /**
     * Prepends MonologBundle handler config when `monolog_handler.enabled` is true, and the Messenger trace middleware
     * on `messenger.trace_middleware.buses` unless `messenger.trace_middleware.enabled` is false.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config  = $this->processConfiguration(new Configuration(), $configs);

        if ($container->hasExtension('monolog')) {
            $monolog = $config['monolog_handler'] ?? [];
            if ((bool) ($monolog['enabled'] ?? false) && class_exists(AbstractProcessingHandler::class)) {
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
        }

        $traceMiddleware = $config['messenger']['trace_middleware'];
        if ((bool) $traceMiddleware['enabled'] && $container->hasExtension('framework') && interface_exists(MessageBusInterface::class)) {
            $buses = [];
            foreach (array_values(array_unique(array_map('strval', $traceMiddleware['buses']))) as $bus) {
                $buses[$bus] = ['middleware' => [BeaconTraceMiddleware::class]];
            }

            $container->prependExtensionConfig('framework', ['messenger' => ['buses' => $buses]]);
        }
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

        // 1.25: with a credentials provider the DSN may come from it at runtime, so an empty nowo_beacon.dsn no longer
        // disables reporting at compile time (the client then drops events while neither has a DSN).
        $providerId  = $this->registerCredentials($container, $config);
        $hasProvider = $providerId !== null;
        $reporting   = $enabledFlag && ($dsn !== '' || $hasProvider);

        $container->setParameter('nowo.beacon.enabled', $reporting);
        $container->setParameter('nowo.beacon.dsn', $config['dsn'] ?? '');
        $container->setParameter('nowo.beacon.environment', $config['environment']);
        $container->setParameter('nowo.beacon.release', $config['release']);
        $container->setParameter('nowo.beacon.server_name', $serverName);
        $container->setParameter('nowo.beacon.verify_peer', (bool) $config['verify_peer']);
        $container->setParameter('nowo.beacon.timeout', (float) $config['timeout']);
        $container->setParameter('nowo.beacon.ignore_exceptions', $config['ignore_exceptions']);
        $container->setParameter('nowo.beacon.ignore_paths', $config['ignore_paths']);
        $container->setParameter('nowo.beacon.send', $send);
        $transportMode = (string) ($config['transport']['mode'] ?? 'sync');
        $container->setParameter('nowo.beacon.transport.mode', $transportMode);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setParameter('nowo.beacon.ingest_paths', (string) ($config['ingest_paths'] ?? 'auto'));
        // Shared ingest path decision per origin + project id: a server capability only, bounded to 32 entries.
        // Intentionally not `kernel.reset` (audit LO-L4): no request state, and Messenger resets services after every
        // message, which would re-probe an older Beacon once per message.
        $ingestPaths = new Definition(IngestPathResolver::class, [
            '$mode' => (string) ($config['ingest_paths'] ?? 'auto'),
        ]);
        $ingestPaths->setPublic(false);
        $container->setDefinition(IngestPathResolver::class, $ingestPaths);

        $this->registerConnectionTestCommand(
            $container,
            $enabledFlag,
            $config['dsn'] ?? '',
            (bool) $config['verify_peer'],
            (float) $config['timeout'],
            (string) $config['environment'],
            is_string($config['release'] ?? null) ? $config['release'] : null,
            $serverName,
            $hasProvider,
        );

        $this->registerMessengerReportCommand($container, $config['messenger']['failure_transports'] ?? ['failed']);

        $this->registerMessengerRemoteRetry($container, $config, $reporting, $serverName, $hasProvider);

        $this->registerSelfTest($container, $config, $reporting, $hasProvider);

        $checkInsEnabled = $this->registerSchedulerCheckIns($container, $config, $reporting);

        $deploy = new Definition(DeployCommand::class, [
            '$client'             => new Reference(BeaconClientInterface::class),
            '$defaultRelease'     => $config['release'],
            '$defaultEnvironment' => $config['environment'],
        ]);
        $deploy->setAutowired(false);
        $deploy->setPublic(false);
        // REQ-SF-003: definitions registered here are conditional and not autoconfigured, so their tags are
        // required (the console.command tag takes name / description from #[AsCommand]); no tag duplicates an
        // attribute that autoconfiguration would also apply.
        $deploy->addTag('console.command');
        $container->setDefinition(DeployCommand::class, $deploy);

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

        // Before the early return: prepend() adds the middleware to the default bus in every configuration.
        $this->registerTraceMiddleware($container, $reporting);

        if (!$reporting) {
            $this->registerNullClient($container);

            return;
        }

        // Fail fast for literal DSNs; env / placeholder values are validated at runtime.
        if ($isLiteralDsn) {
            (new BeaconDsnParser())->parse($dsn);
            $container->setParameter('nowo.beacon.enabled', true);
        }

        $retry        = $config['transport']['retry'];
        $ingestClient = new Definition(HttpClientInterface::class, [
            new Reference('http_client'),
            (int) $retry['max_retries'],
            (int) $retry['delay_ms'],
            (float) $retry['multiplier'],
            (int) $retry['max_delay_ms'],
            (float) $retry['jitter'],
            array_values(array_map('intval', $retry['status_codes'])),
        ]);
        $ingestClient->setFactory([IngestHttpClientFactory::class, 'create']);
        $ingestClient->setPublic(false);
        $container->setDefinition('nowo.beacon.ingest_http_client', $ingestClient);

        // Synchronous sends: same retries, but a Retry-After above max_delay_ms ends them (REQ-RUNTIME-001).
        $syncIngestClient = new Definition(HttpClientInterface::class, [...$ingestClient->getArguments(), true]);
        $syncIngestClient->setFactory([IngestHttpClientFactory::class, 'create']);
        $syncIngestClient->setPublic(false);
        $container->setDefinition('nowo.beacon.ingest_http_client.sync', $syncIngestClient);

        $factory = new Definition(BeaconClientFactory::class, [
            '$parser'              => new Reference(BeaconDsnParser::class),
            '$httpClient'          => new Reference('nowo.beacon.ingest_http_client'),
            '$logger'              => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$userContextProvider' => new Reference(UserContextProviderInterface::class),
            '$breadcrumbBuffer'    => new Reference(BreadcrumbBuffer::class),
            '$requestStack'        => new Reference('request_stack', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$scope'               => new Reference(Scope::class),
            '$spanBuffer'          => new Reference(SpanBuffer::class),
            '$pendingRegistry'     => new Reference(PendingTransportRegistry::class),
            '$messageBus'          => new Reference('messenger.default_bus', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$clock'               => new Reference(ClockInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$traceIdProvider'     => new Reference(TraceIdProvider::class),
            '$syncHttpClient'      => new Reference('nowo.beacon.ingest_http_client.sync'),
            '$ingestPaths'         => new Reference(IngestPathResolver::class),
        ]);
        $factory->setAutowired(false);
        $factory->setPublic(false);
        $container->setDefinition(BeaconClientFactory::class, $factory);

        $beforeSend = null;
        if (is_string($config['before_send'] ?? null) && $config['before_send'] !== '') {
            $beforeSend = new Reference($config['before_send']);
        }

        $client = new Definition(BeaconClientInterface::class);
        $client->setFactory([new Reference(BeaconClientFactory::class), $hasProvider ? 'createResolving' : 'create']);
        $client->setArguments([
            ...($hasProvider
                ? ['$resolver' => new Reference(BeaconCredentialsResolver::class)]
                : ['$enabled' => $enabledFlag, '$dsn' => $config['dsn'] ?? '']),
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

        if ($transportMode === 'messenger' && interface_exists(MessageBusInterface::class)) {
            $syncTransport = new Definition(EnvelopeTransport::class);
            $syncTransport->setFactory([new Reference(BeaconClientFactory::class), $hasProvider ? 'createResolvingSyncTransport' : 'createSyncTransport']);
            $syncTransport->setArguments([
                ...($hasProvider
                    ? ['$resolver' => new Reference(BeaconCredentialsResolver::class)]
                    : ['$enabled' => $enabledFlag, '$dsn' => $config['dsn'] ?? '']),
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
            $listener->setArgument('$ignorePaths', $config['ignore_paths']);
            $listener->setArgument('$sendClient', (bool) ($send['client'] ?? false));
            $listener->setArgument('$ignoreHttpClientErrors', (bool) ($config['ignore_http_client_errors'] ?? false));
        } else {
            $container->removeDefinition(BeaconExceptionListener::class);
        }

        $traceRequest = new Definition(BeaconTraceRequestListener::class, [
            '$traceIdProvider' => new Reference(TraceIdProvider::class),
            '$enabled'         => true,
        ]);
        $traceRequest->addTag('kernel.event_subscriber');
        $traceRequest->setPublic(false);
        $container->setDefinition(BeaconTraceRequestListener::class, $traceRequest);

        if ((bool) ($config['register_fatal_handler'] ?? true)) {
            $fatal = new Definition(BeaconFatalErrorHandler::class, [
                '$client'  => new Reference(BeaconClientInterface::class),
                '$enabled' => true,
            ]);
            // Public so NowoBeaconBundle::boot() can instantiate it; nothing else references it.
            $fatal->setPublic(true);
            $container->setDefinition(BeaconFatalErrorHandler::class, $fatal);
        }

        if ((bool) ($config['register_php_warning_handler'] ?? true)) {
            $warningOpts = $config['php_warning_handler'] ?? [];
            $levelNames  = $warningOpts['levels'] ?? [];
            $levels      = $levelNames === []
                ? BeaconPhpWarningHandler::DEFAULT_LEVELS
                : BeaconPhpWarningHandler::levelsFromNames($levelNames);
            $warning = new Definition(BeaconPhpWarningHandler::class, [
                '$client'          => new Reference(BeaconClientInterface::class),
                '$enabled'         => true,
                '$levels'          => $levels,
                '$continue'        => (bool) ($warningOpts['continue'] ?? true),
                '$captureSilenced' => (bool) ($warningOpts['capture_silenced'] ?? false),
            ]);
            // Public so NowoBeaconBundle::boot() can register set_error_handler.
            $warning->setPublic(true);
            $container->setDefinition(BeaconPhpWarningHandler::class, $warning);
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
            && class_exists(WorkerMessageFailedEvent::class)
        ) {
            // Failure-transport ids for extra.messenger (RecordingFailureSenderPass wraps the failure senders).
            $recorder = new Definition(FailureTransportRecorder::class);
            $recorder->addTag('kernel.event_subscriber');
            $recorder->addTag('kernel.reset', ['method' => 'reset']);
            $recorder->setPublic(false);
            $container->setDefinition(FailureTransportRecorder::class, $recorder);

            $messenger = new Definition(BeaconMessengerFailedListener::class, [
                '$client'                  => new Reference(BeaconClientInterface::class),
                '$enabled'                 => true,
                '$ignoreExceptions'        => $config['ignore_exceptions'],
                '$includeSchedulerContext' => (bool) ($config['include_scheduler_context'] ?? true),
                '$traceIdProvider'         => new Reference(TraceIdProvider::class),
                '$ignoreMessageClasses'    => $config['messenger']['ignore_message_classes'] ?? [],
                '$checkInRuns'             => $checkInsEnabled ? new Reference(SchedulerRunRegistry::class) : null,
                '$failureRecorder'         => new Reference(FailureTransportRecorder::class),
            ]);
            // After Symfony's SendFailedMessageToFailureTransportListener (-100), before transactions (-1024).
            $messenger->addTag('kernel.event_listener', [
                'event'    => WorkerMessageFailedEvent::class,
                'priority' => BeaconMessengerFailedListener::PRIORITY,
            ]);
            $messenger->setPublic(false);
            $container->setDefinition(BeaconMessengerFailedListener::class, $messenger);
        }

        $this->registerMessengerObservability($container, $config);

        if ((bool) ($config['auto_http_transaction'] ?? false)) {
            $tx = new Definition(BeaconRequestTransactionListener::class, [
                '$client'       => new Reference(BeaconClientInterface::class),
                '$enabled'      => true,
                '$ignorePaths'  => $config['ignore_paths'],
                '$sampleRate'   => (float) $config['http_transaction_sample_rate'],
                '$ignoreRoutes' => array_values(array_map('strval', $config['ignore_routes'])),
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
        if ((bool) ($monolog['enabled'] ?? false) && class_exists(AbstractProcessingHandler::class)) {
            $handler = new Definition(BeaconMonologHandler::class, [
                '$client' => new Reference(BeaconClientInterface::class),
                '$level'  => (string) ($monolog['level'] ?? 'error'),
                // Same matcher as the exception listener: main ignore list + handler-only extras.
                '$ignoreExceptions' => array_values(array_unique([
                    ...$config['ignore_exceptions'],
                    ...($monolog['ignore_exceptions'] ?? []),
                ])),
                '$ignoreHttpClientErrors' => (bool) ($monolog['ignore_http_client_errors'] ?? true),
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
            && interface_exists(Middleware::class)
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
     * Registers opt-in Messenger transactions, retry visibility and worker memory warnings.
     *
     * @param array<string, mixed> $config
     */
    private function registerMessengerObservability(ContainerBuilder $container, array $config): void
    {
        if (!class_exists(WorkerMessageFailedEvent::class)) {
            return; // @codeCoverageIgnore
        }

        $messenger = $config['messenger'] ?? [];

        if ((bool) ($messenger['transactions'] ?? false)) {
            $tx = new Definition(BeaconMessengerTransactionListener::class, [
                '$client'               => new Reference(BeaconClientInterface::class),
                '$spanBuffer'           => new Reference(SpanBuffer::class),
                '$sampleRate'           => (float) ($messenger['transaction_sample_rate'] ?? 1.0),
                '$traceIdProvider'      => new Reference(TraceIdProvider::class),
                '$ignoreMessageClasses' => $messenger['ignore_message_classes'] ?? [],
            ]);
            $tx->addTag('kernel.event_subscriber');
            $tx->addTag('kernel.reset', ['method' => 'reset']);
            $tx->setPublic(false);
            $container->setDefinition(BeaconMessengerTransactionListener::class, $tx);
        }

        $reportRetries   = (bool) ($messenger['report_retries'] ?? false);
        $reportRecovered = (bool) ($messenger['report_recovered'] ?? false);
        if ($reportRetries || $reportRecovered) {
            $retry = new Definition(BeaconMessengerRetryListener::class, [
                '$client'               => new Reference(BeaconClientInterface::class),
                '$reportRetries'        => $reportRetries,
                '$reportRecovered'      => $reportRecovered,
                '$sampleRate'           => (float) ($messenger['retry_sample_rate'] ?? 1.0),
                '$ignoreExceptions'     => $config['ignore_exceptions'],
                '$traceIdProvider'      => new Reference(TraceIdProvider::class),
                '$ignoreMessageClasses' => $messenger['ignore_message_classes'] ?? [],
            ]);
            $retry->addTag('kernel.event_subscriber');
            $retry->setPublic(false);
            $container->setDefinition(BeaconMessengerRetryListener::class, $retry);
        }

        $ratio = $messenger['worker_memory_warning_ratio'] ?? null;
        if ($ratio !== null) {
            $memory = new Definition(BeaconWorkerMemoryListener::class, [
                '$client' => new Reference(BeaconClientInterface::class),
                '$ratio'  => (float) $ratio,
            ]);
            $memory->addTag('kernel.event_subscriber');
            $memory->setPublic(false);
            $container->setDefinition(BeaconWorkerMemoryListener::class, $memory);
        }
    }

    /**
     * Registers Scheduler check-in services: the catalog builder and `nowo:beacon:scheduler-catalog`
     * whenever symfony/scheduler is installed, and the worker listener when check-ins are enabled and
     * reporting is configured. Enabling check-ins without symfony/scheduler fails compilation.
     *
     * @param array<string, mixed> $config
     *
     * @return bool Whether the check-in listener is registered
     */
    private function registerSchedulerCheckIns(ContainerBuilder $container, array $config, bool $reportingEnabled): bool
    {
        $checkIns = $config['scheduler']['check_ins'] ?? [];
        $enabled  = (bool) ($checkIns['enabled'] ?? false);

        if (!class_exists(ScheduledStamp::class) || !class_exists(WorkerStartedEvent::class)) {
            // @codeCoverageIgnoreStart
            if ($enabled) {
                throw new LogicException('nowo_beacon.scheduler.check_ins.enabled requires symfony/scheduler and symfony/messenger. Run "composer require symfony/scheduler" or set it to false.');
            }

            return false;
            // @codeCoverageIgnoreEnd
        }

        $policy = new Definition(CheckInPolicy::class, [
            '$maxRuntime'   => $checkIns['max_runtime'] ?? [],
            '$ignore'       => array_values($checkIns['ignore'] ?? []),
            '$descriptions' => $checkIns['descriptions'] ?? [],
        ]);
        $policy->setPublic(false);
        $container->setDefinition(CheckInPolicy::class, $policy);

        // $commandLoader is set by TaskDescriptionCommandLoaderPass once console.command_loader exists.
        $descriptions = new Definition(TaskDescriptionResolver::class, [
            '$policy'        => new Reference(CheckInPolicy::class),
            '$commandLoader' => null,
        ]);
        $descriptions->setPublic(false);
        $container->setDefinition(TaskDescriptionResolver::class, $descriptions);

        $builder = new Definition(ScheduleCatalogBuilder::class, [
            '$policy'       => new Reference(CheckInPolicy::class),
            '$providers'    => null, // set by ScheduleProviderLocatorPass
            '$clock'        => new Reference(ClockInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$logger'       => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$descriptions' => new Reference(TaskDescriptionResolver::class),
        ]);
        $builder->setPublic(false);
        $container->setDefinition(ScheduleCatalogBuilder::class, $builder);

        $command = new Definition(SchedulerCatalogCommand::class, [
            '$client'          => new Reference(BeaconClientInterface::class),
            '$catalogBuilder'  => new Reference(ScheduleCatalogBuilder::class),
            '$checkInsEnabled' => $enabled,
        ]);
        $command->setAutowired(false);
        $command->setPublic(false);
        $command->addTag('console.command');
        $container->setDefinition(SchedulerCatalogCommand::class, $command);

        if (!$enabled || !$reportingEnabled) {
            return false;
        }

        $runs = new Definition(SchedulerRunRegistry::class);
        $runs->addTag('kernel.reset', ['method' => 'reset']);
        $runs->setPublic(false);
        $container->setDefinition(SchedulerRunRegistry::class, $runs);

        $listener = new Definition(BeaconSchedulerCheckInListener::class, [
            '$client'              => new Reference(BeaconClientInterface::class),
            '$catalogBuilder'      => new Reference(ScheduleCatalogBuilder::class),
            '$policy'              => new Reference(CheckInPolicy::class),
            '$runs'                => new Reference(SchedulerRunRegistry::class),
            '$catalogRefreshHours' => (int) ($checkIns['catalog_refresh_hours'] ?? 24),
            '$pendingTransports'   => new Reference(PendingTransportRegistry::class),
            '$clock'               => new Reference(ClockInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$logger'              => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        $listener->addTag('kernel.event_subscriber');
        $listener->setPublic(false);
        $container->setDefinition(BeaconSchedulerCheckInListener::class, $listener);

        return true;
    }

    /**
     * Registers `nowo:beacon:messenger-report` (also in NullBeaconClient mode, where it explains why nothing is sent).
     *
     * @param list<string> $failureTransports
     */
    private function registerMessengerReportCommand(ContainerBuilder $container, array $failureTransports): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            return; // @codeCoverageIgnore
        }

        $command = new Definition(MessengerReportCommand::class, [
            '$client'            => new Reference(BeaconClientInterface::class),
            '$receiverLocator'   => new Reference('messenger.receiver_locator', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$failureTransports' => $failureTransports,
        ]);
        $command->setAutowired(false);
        $command->setPublic(false);
        $command->addTag('console.command');
        $container->setDefinition(MessengerReportCommand::class, $command);
    }

    /**
     * Registers `nowo:beacon:messenger-commands` (whenever Messenger is installed; it explains when the
     * feature is off) and, when `messenger.remote_retry.enabled` and reporting are on, the poller
     * services. Enabling it without symfony/messenger fails compilation; a literal (non-env) worker key
     * is validated at compile time, env values at runtime.
     *
     * @param array<string, mixed> $config
     */
    private function registerMessengerRemoteRetry(ContainerBuilder $container, array $config, bool $reportingEnabled, string $serverName, bool $hasProvider = false): void
    {
        $remote  = $config['messenger']['remote_retry'] ?? [];
        $enabled = (bool) ($remote['enabled'] ?? false);

        if (!interface_exists(MessageBusInterface::class)) {
            // @codeCoverageIgnoreStart
            if ($enabled) {
                throw new LogicException('nowo_beacon.messenger.remote_retry.enabled requires symfony/messenger. Run "composer require symfony/messenger" or set it to false.');
            }

            return;
            // @codeCoverageIgnoreEnd
        }

        $command = new Definition(MessengerCommandsCommand::class, [
            '$poller' => null,
        ]);
        $command->setAutowired(false);
        $command->setPublic(false);
        $command->addTag('console.command');
        $container->setDefinition(MessengerCommandsCommand::class, $command);

        if (!$enabled || !$reportingEnabled) {
            return;
        }

        $workerKey = $remote['worker_key'] ?? '';
        if (is_string($workerKey) && $workerKey !== '' && !$this->containsEnvPlaceholder($container, $workerKey) && !WorkerKey::isValid($workerKey)) {
            throw new LogicException('nowo_beacon.messenger.remote_retry.worker_key is not a Beacon worker key (expected "bwk_…"). Copy it from Beacon → Settings → Alerts → Remote Messenger commands.');
        }

        $failureTransports = array_values($remote['failure_transports'] ?? []);
        if ($failureTransports === []) {
            $failureTransports = array_values($config['messenger']['failure_transports'] ?? ['failed']);
        }

        $credentials = new Definition(RemoteRetryCredentials::class, [
            '$parser'       => new Reference(BeaconDsnParser::class),
            '$rawDsn'       => $config['dsn'] ?? '',
            '$rawWorkerKey' => $workerKey,
            '$resolver'     => $hasProvider ? new Reference(BeaconCredentialsResolver::class) : null,
        ]);
        $credentials->setPublic(false);
        $container->setDefinition(RemoteRetryCredentials::class, $credentials);

        $client = new Definition(RemoteCommandClient::class, [
            '$httpClient'  => new Reference('http_client'),
            '$credentials' => new Reference(RemoteRetryCredentials::class),
            '$serverName'  => $serverName,
            '$environment' => $config['environment'],
            '$verifyPeer'  => (bool) $config['verify_peer'],
            '$clock'       => new Reference(ClockInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$ingestPaths' => new Reference(IngestPathResolver::class),
        ]);
        $client->setPublic(false);
        $container->setDefinition(RemoteCommandClient::class, $client);

        $executor = new Definition(FailedMessageCommandExecutor::class, [
            '$credentials'       => new Reference(RemoteRetryCredentials::class),
            '$receiverLocator'   => new Reference('messenger.receiver_locator', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$allowedActions'    => array_values($remote['allowed_actions'] ?? []),
            '$failureTransports' => $failureTransports,
            '$cache'             => new Reference('cache.app', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$clock'             => new Reference(ClockInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$logger'            => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        $executor->setPublic(false);
        $container->setDefinition(FailedMessageCommandExecutor::class, $executor);

        $poller = new Definition(RemoteCommandPoller::class, [
            '$client'       => new Reference(RemoteCommandClient::class),
            '$executor'     => new Reference(FailedMessageCommandExecutor::class),
            '$credentials'  => new Reference(RemoteRetryCredentials::class),
            '$pollInterval' => (int) ($remote['poll_interval'] ?? 30),
            '$logger'       => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        if ((bool) ($remote['poll_in_workers'] ?? true)) {
            $poller->addTag('kernel.event_subscriber');
        }
        // Not tagged kernel.reset on purpose: Messenger resets services after each message (see the class doc).
        $poller->setPublic(false);
        $container->setDefinition(RemoteCommandPoller::class, $poller);

        $command->setArgument('$poller', new Reference(RemoteCommandPoller::class));
    }

    /**
     * Whether a configuration value is (or contains) an unresolved `%env(...)%` placeholder.
     */
    private function containsEnvPlaceholder(ContainerBuilder $container, string $value): bool
    {
        if (str_contains($value, '%env(')) {
            return true;
        }

        $usedEnvs = null;
        $container->resolveEnvPlaceholders($value, null, $usedEnvs);

        return is_array($usedEnvs) && $usedEnvs !== [];
    }

    /**
     * Registers the self-test service, console command, Messenger fail handler, optional HTTP
     * controller and the `nowo_beacon` route loader (also in NullBeaconClient mode, where the
     * command explains why nothing can be sent).
     *
     * @param array<string, mixed> $config
     */
    private function registerSelfTest(ContainerBuilder $container, array $config, bool $reportingEnabled, bool $hasProvider = false): void
    {
        $hasMessenger = interface_exists(MessageBusInterface::class);

        $service = new Definition(BeaconSelfTest::class, [
            '$client'            => new Reference(BeaconClientInterface::class),
            '$parser'            => new Reference(BeaconDsnParser::class),
            '$dsn'               => $config['dsn'] ?? '',
            '$reportingEnabled'  => $reportingEnabled,
            '$bus'               => $hasMessenger ? new Reference('messenger.default_bus', ContainerBuilder::NULL_ON_INVALID_REFERENCE) : null,
            '$pendingTransports' => new Reference(PendingTransportRegistry::class),
            '$exceptionListener' => (bool) $config['register_error_listener'],
            '$consoleListener'   => (bool) $config['register_console_listener'],
            '$phpWarningHandler' => (bool) $config['register_php_warning_handler'],
            '$messengerListener' => (bool) $config['register_messenger_listener'],
            '$credentials'       => $hasProvider ? new Reference(BeaconCredentialsResolver::class) : null,
        ]);
        $service->setAutowired(false);
        $service->setPublic(false);
        $container->setDefinition(BeaconSelfTest::class, $service);

        $command = new Definition(SelfTestCommand::class, [
            '$selfTest' => new Reference(BeaconSelfTest::class),
        ]);
        $command->setAutowired(false);
        $command->setPublic(false);
        $command->addTag('console.command');
        $container->setDefinition(SelfTestCommand::class, $command);

        if ($hasMessenger) {
            $handler = new Definition(BeaconSelfTestFailHandler::class);
            $handler->addTag('messenger.message_handler', ['handles' => BeaconSelfTestFailMessage::class]);
            $handler->setPublic(false);
            $container->setDefinition(BeaconSelfTestFailHandler::class, $handler);
        }

        $http    = $config['self_test']['http'] ?? [];
        $enabled = (bool) ($http['enabled'] ?? false);
        $path    = (string) ($http['path'] ?? '/_beacon/self-test');

        $loader = new Definition(BeaconRouteLoader::class, [
            '$selfTestEnabled' => $enabled,
            '$selfTestPath'    => $path,
        ]);
        $loader->setAutowired(false);
        $loader->setPublic(false);
        $loader->addTag('routing.loader');
        $container->setDefinition(BeaconRouteLoader::class, $loader);

        if (!$enabled) {
            return;
        }

        $controller = new Definition(SelfTestController::class, [
            '$selfTest'             => new Reference(BeaconSelfTest::class),
            '$roles'                => array_values($http['roles'] ?? ['ROLE_ADMIN']),
            '$csrfTokenManager'     => new Reference('security.csrf.token_manager', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$authorizationChecker' => new Reference('security.authorization_checker', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ]);
        $controller->setAutowired(false);
        // Never autoconfigured: on Symfony >= 7.3 that would tag the #[Route] class `routing.controller` and the host's
        // `routing.controllers` import would expose the endpoint without the opt-in loader (REQ-SF-004 rule 7).
        $controller->setAutoconfigured(false);
        $controller->setPublic(true);
        $controller->addTag('controller.service_arguments');
        $container->setDefinition(SelfTestController::class, $controller);
    }

    /**
     * Registers {@see BeaconTraceMiddleware} whenever Messenger is installed (the same condition as the bus middleware
     * prepended in {@see prepend()}), as a pass-through when the bundle is disabled or the DSN is empty.
     */
    private function registerTraceMiddleware(ContainerBuilder $container, bool $enabled): void
    {
        if (interface_exists(MessageBusInterface::class)) {
            $traceMiddleware = new Definition(BeaconTraceMiddleware::class, [
                '$traceIdProvider' => new Reference(TraceIdProvider::class),
                '$enabled'         => $enabled,
            ]);
            $traceMiddleware->setPublic(false);
            $container->setDefinition(BeaconTraceMiddleware::class, $traceMiddleware);
        }
    }

    /**
     * Registers the credentials resolver (always: without a provider it only parses the configuration, as before), the
     * rotate command, the optional InstanceSettings bridge and, with a provider, the listener that arms the resolver.
     *
     * The provider service must implement BeaconCredentialsProviderInterface (checked by CredentialsProviderPass, which
     * also hands it to the rotate command when it is a BeaconCredentialsStoreInterface).
     *
     * @param array<string, mixed> $config
     *
     * @return string|null id of the credentials provider service
     */
    private function registerCredentials(ContainerBuilder $container, array $config): ?string
    {
        $providerId = is_string($config['credentials_provider'] ?? null) ? trim($config['credentials_provider']) : null;

        if ((bool) ($config['instance_settings_bridge'] ?? false)) {
            if (!interface_exists(InstanceSettingsInterface::class)) {
                // @codeCoverageIgnoreStart (the dev install always has the InstanceSettings types)
                throw new LogicException('nowo_beacon.instance_settings_bridge requires nowo-tech/instance-settings-bundle. Run "composer require nowo-tech/instance-settings-bundle" or set it to false.');
                // @codeCoverageIgnoreEnd
            }

            $section = new Definition(BeaconSettingsSection::class);
            $section->setAutowired(false);
            $section->setAutoconfigured(false);
            $section->setPublic(false);
            $section->addTag('nowo_instance_settings.section');
            $container->setDefinition(BeaconSettingsSection::class, $section);

            $store = new Definition(InstanceSettingsCredentialsStore::class, [
                '$settings' => new Reference(InstanceSettingsInterface::class),
            ]);
            $store->setPublic(false);
            $container->setDefinition(self::INSTANCE_SETTINGS_STORE, $store);

            $listener = new Definition(InstanceSettingsCredentialsListener::class, [
                '$resolver' => new Reference(BeaconCredentialsResolver::class),
                '$logger'   => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            ]);
            $listener->addTag('kernel.event_listener', ['event' => SettingsChangedEvent::class]);
            $listener->setPublic(false);
            $container->setDefinition(InstanceSettingsCredentialsListener::class, $listener);

            $providerId ??= self::INSTANCE_SETTINGS_STORE;
        }

        $container->setParameter('nowo.beacon.credentials_provider', $providerId);

        $resolver = new Definition(BeaconCredentialsResolver::class, [
            '$parser'          => new Reference(BeaconDsnParser::class),
            '$configDsn'       => $config['dsn'] ?? '',
            '$configWorkerKey' => $config['messenger']['remote_retry']['worker_key'] ?? '',
            '$provider'        => $providerId !== null ? new Reference($providerId) : null,
            '$cacheTtl'        => (int) ($config['credentials_cache_ttl'] ?? BeaconCredentialsResolver::DEFAULT_CACHE_TTL),
            '$clock'           => new Reference(ClockInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$logger'          => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$allowedOrigins'  => array_values(array_map(
                static fn (mixed $origin): string => strtolower(rtrim(trim((string) $origin), '/')),
                (array) ($config['credentials_allowed_origins'] ?? []),
            )),
        ]);
        // Expires the cache only; the TTL keeps it correct in workers whose kernel is never reset.
        $resolver->addTag('kernel.reset', ['method' => 'reset']);
        $resolver->setPublic(false);
        $container->setDefinition(BeaconCredentialsResolver::class, $resolver);

        $rotate = new Definition(RotateCredentialsCommand::class, [
            '$resolver' => new Reference(BeaconCredentialsResolver::class),
            '$tester'   => new Reference(BeaconConnectionTester::class),
            '$parser'   => new Reference(BeaconDsnParser::class),
            '$store'    => null, // set by CredentialsProviderPass when the provider is writable
        ]);
        $rotate->setAutowired(false);
        $rotate->setPublic(false);
        $rotate->addTag('console.command');
        $container->setDefinition(RotateCredentialsCommand::class, $rotate);

        if ($providerId !== null) {
            $arm = new Definition(BeaconCredentialsArmListener::class, [
                '$resolver' => new Reference(BeaconCredentialsResolver::class),
            ]);
            $arm->addTag('kernel.event_subscriber');
            $arm->setPublic(false);
            $container->setDefinition(BeaconCredentialsArmListener::class, $arm);
        }

        return $providerId;
    }

    /**
     * Extension alias (`nowo_beacon`).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Registers {@see BeaconConnectionTester} and {@see TestConnectionCommand} when Console is available.
     *
     * Always registered (including NullBeaconClient mode) so operators get a clear failure for empty DSNs.
     */
    private function registerConnectionTestCommand(
        ContainerBuilder $container,
        bool $reportingEnabled,
        #[SensitiveParameter]
        mixed $dsn,
        bool $verifyPeer,
        float $timeout,
        string $environment,
        ?string $release,
        string $serverName,
        bool $hasProvider = false,
    ): void {
        $tester = new Definition(BeaconConnectionTester::class, [
            '$parser'           => new Reference(BeaconDsnParser::class),
            '$httpClient'       => new Reference('http_client'),
            '$reportingEnabled' => $reportingEnabled && (trim((string) $dsn) !== '' || $hasProvider),
            '$dsn'              => $dsn,
            '$verifyPeer'       => $verifyPeer,
            '$timeout'          => $timeout,
            '$environment'      => $environment,
            '$release'          => $release,
            '$serverName'       => $serverName,
            '$logger'           => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            '$ingestPaths'      => new Reference(IngestPathResolver::class),
            '$credentials'      => $hasProvider ? new Reference(BeaconCredentialsResolver::class) : null,
        ]);
        $tester->setAutowired(false);
        $tester->setPublic(false);
        $container->setDefinition(BeaconConnectionTester::class, $tester);

        $command = new Definition(TestConnectionCommand::class, [
            '$tester' => new Reference(BeaconConnectionTester::class),
        ]);
        $command->setAutowired(false);
        $command->setPublic(false);
        $command->addTag('console.command');
        $container->setDefinition(TestConnectionCommand::class, $command);
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
