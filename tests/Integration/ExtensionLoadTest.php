<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Monolog\Handler\AbstractProcessingHandler;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\DeployCommand;
use Nowo\BeaconBundle\Command\MessengerReportCommand;
use Nowo\BeaconBundle\Command\SelfTestCommand;
use Nowo\BeaconBundle\Command\TestConnectionCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Controller\SelfTestController;
use Nowo\BeaconBundle\DependencyInjection\Configuration;
use Nowo\BeaconBundle\DependencyInjection\NowoBeaconExtension;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessageHandler;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerRetryListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerTransactionListener;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use Nowo\BeaconBundle\EventListener\BeaconRequestScopeResetListener;
use Nowo\BeaconBundle\EventListener\BeaconRequestTransactionListener;
use Nowo\BeaconBundle\EventListener\BeaconWorkerMemoryListener;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use Nowo\BeaconBundle\Instrumentation\DoctrineSqlMiddleware;
use Nowo\BeaconBundle\Instrumentation\TraceableBeaconHttpClient;
use Nowo\BeaconBundle\Messenger\BeaconTraceMiddleware;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Nowo\BeaconBundle\Routing\BeaconRouteLoader;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailHandler;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use const E_USER_WARNING;
use const E_WARNING;

final class ExtensionLoadTest extends TestCase
{
    public function testEmptyDsnRegistersNullClient(): void
    {
        $container = $this->createContainer();
        $extension = new NowoBeaconExtension();
        $extension->load([['enabled' => true, 'dsn' => '']], $container);

        self::assertFalse($container->getParameter('nowo.beacon.enabled'));
        self::assertTrue($container->hasAlias(BeaconClientInterface::class));
        self::assertSame(NullBeaconClient::class, (string) $container->getAlias(BeaconClientInterface::class));
        self::assertTrue($container->hasDefinition(NullBeaconClient::class));
        self::assertFalse($container->hasDefinition(BeaconExceptionListener::class));
        self::assertSame(Configuration::ALIAS, $extension->getAlias());
        self::assertTrue($container->hasDefinition(BeaconConnectionTester::class));
        self::assertTrue($container->hasDefinition(TestConnectionCommand::class));
    }

    public function testEnabledFalseRegistersNullClientEvenWithValidDsn(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled' => false,
            'dsn'     => 'https://pubkey:secret@beacon.example.com/5',
        ]], $container);

        self::assertFalse($container->getParameter('nowo.beacon.enabled'));
        self::assertSame(NullBeaconClient::class, (string) $container->getAlias(BeaconClientInterface::class));
        self::assertFalse($container->hasDefinition(BeaconExceptionListener::class));
    }

    public function testValidDsnWiresBeaconClientFactory(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'           => true,
            'dsn'               => 'https://pubkey:secret@beacon.example.com:9444/5',
            'environment'       => 'test',
            'release'           => '1.0.0',
            'server_name'       => 'ci-host',
            'verify_peer'       => false,
            'timeout'           => 1.5,
            'ignore_exceptions' => [RuntimeException::class],
            'before_send'       => 'app.beacon_before_send',
        ]], $container);

        self::assertTrue($container->getParameter('nowo.beacon.enabled'));
        self::assertSame('https://pubkey:secret@beacon.example.com:9444/5', $container->getParameter('nowo.beacon.dsn'));
        self::assertTrue($container->hasDefinition(BeaconClientFactory::class));
        self::assertTrue($container->hasDefinition('nowo.beacon.client'));
        self::assertSame('nowo.beacon.client', (string) $container->getAlias(BeaconClientInterface::class));
        self::assertTrue($container->hasDefinition(BeaconExceptionListener::class));

        $clientDefinition = $container->getDefinition('nowo.beacon.client');
        self::assertEquals([new Reference(BeaconClientFactory::class), 'create'], $clientDefinition->getFactory());
        self::assertTrue($clientDefinition->getArgument('$enabled'));
        self::assertSame('https://pubkey:secret@beacon.example.com:9444/5', $clientDefinition->getArgument('$dsn'));
        self::assertSame(1.5, $clientDefinition->getArgument('$timeout'));
        self::assertFalse($clientDefinition->getArgument('$verifyPeer'));
        self::assertEquals(new Reference('app.beacon_before_send'), $clientDefinition->getArgument('$beforeSend'));

        $listenerDefinition = $container->getDefinition(BeaconExceptionListener::class);
        self::assertEquals(new Reference(BeaconClientInterface::class), $listenerDefinition->getArgument('$client'));
        self::assertTrue($listenerDefinition->getArgument('$enabled'));
        self::assertSame([RuntimeException::class], $listenerDefinition->getArgument('$ignoreExceptions'));
        self::assertTrue($listenerDefinition->getArgument('$sendRequest'));
        self::assertSame([
            '/_profiler',
            '/_wdt',
            '/build',
            '/assets',
            '/health',
            '/.well-known/appspecific/com.chrome.devtools.json',
        ], $listenerDefinition->getArgument('$ignorePaths'));
        self::assertArrayHasKey('user', $clientDefinition->getArgument('$send'));
        self::assertFalse($clientDefinition->getArgument('$send')['user']);
    }

    public function testWorkerSafetyServicesAreRegistered(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled' => true,
            'dsn'     => 'https://pubkey:secret@beacon.example.com/5',
        ]], $container);

        self::assertTrue($container->hasDefinition(BeaconRequestScopeResetListener::class));
        self::assertTrue($container->getDefinition(BeaconRequestScopeResetListener::class)->hasTag('kernel.event_subscriber'));

        $fatal = $container->getDefinition(BeaconFatalErrorHandler::class);
        self::assertTrue($fatal->isPublic());
        self::assertFalse($fatal->hasMethodCall('register'));

        $warning = $container->getDefinition(BeaconPhpWarningHandler::class);
        self::assertTrue($warning->isPublic());
        self::assertSame(BeaconPhpWarningHandler::DEFAULT_LEVELS, $warning->getArgument('$levels'));
        self::assertTrue($warning->getArgument('$continue'));
    }

    public function testPhpWarningHandlerCanBeDisabled(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'                      => true,
            'dsn'                          => 'https://pubkey:secret@beacon.example.com/5',
            'register_php_warning_handler' => false,
        ]], $container);

        self::assertFalse($container->hasDefinition(BeaconPhpWarningHandler::class));
    }

    public function testPhpWarningHandlerCustomLevels(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'                      => true,
            'dsn'                          => 'https://pubkey:secret@beacon.example.com/5',
            'register_php_warning_handler' => true,
            'php_warning_handler'          => [
                'continue'         => false,
                'capture_silenced' => true,
                'levels'           => ['warning', 'user_warning'],
            ],
        ]], $container);

        $warning = $container->getDefinition(BeaconPhpWarningHandler::class);
        self::assertSame(E_WARNING | E_USER_WARNING, $warning->getArgument('$levels'));
        self::assertFalse($warning->getArgument('$continue'));
        self::assertTrue($warning->getArgument('$captureSilenced'));
    }

    public function testRegisterErrorListenerFalseRemovesListener(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'                 => true,
            'dsn'                     => 'https://pubkey:secret@beacon.example.com/5',
            'register_error_listener' => false,
        ]], $container);

        self::assertFalse($container->hasDefinition(BeaconExceptionListener::class));
        self::assertSame('nowo.beacon.client', (string) $container->getAlias(BeaconClientInterface::class));
    }

    public function testInvalidLiteralDsnThrowsAtCompileTime(): void
    {
        $this->expectException(InvalidBeaconDsnException::class);

        $container = $this->createContainer();
        (new NowoBeaconExtension())->load([[
            'enabled' => true,
            'dsn'     => 'https://pubkey:secret@beacon.example.com/not-a-number',
        ]], $container);
    }

    public function testAsyncTransportRegistersFlushListener(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'   => true,
            'dsn'       => 'https://pubkey:secret@beacon.example.com/5',
            'transport' => ['mode' => 'async'],
        ]], $container);

        self::assertTrue($container->hasDefinition(FlushPendingTransportsListener::class));
    }

    public function testMessengerTransportRegistersHandlerAndFlush(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'   => true,
            'dsn'       => 'https://pubkey:secret@beacon.example.com/5',
            'transport' => ['mode' => 'messenger'],
        ]], $container);

        self::assertTrue($container->hasDefinition(FlushPendingTransportsListener::class));
        self::assertTrue($container->hasDefinition('nowo.beacon.sync_transport'));
        self::assertTrue($container->hasDefinition(SendBeaconEnvelopeMessageHandler::class));
    }

    public function testDisablesOptionalListenersAndEnablesInstrumentation(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'                     => true,
            'dsn'                         => 'https://pubkey:secret@beacon.example.com/5',
            'register_console_listener'   => false,
            'register_messenger_listener' => true,
            'include_scheduler_context'   => false,
            'auto_http_transaction'       => true,
            'monolog_handler'             => ['enabled' => true, 'level' => 'warning'],
            'instrumentation'             => [
                'doctrine'    => true,
                'http_client' => true,
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(BeaconConsoleErrorListener::class));
        self::assertTrue($container->hasDefinition(BeaconMessengerFailedListener::class));
        self::assertFalse(
            $container->getDefinition(BeaconMessengerFailedListener::class)->getArgument('$includeSchedulerContext'),
        );
        self::assertTrue($container->hasDefinition(BeaconRequestTransactionListener::class));
        self::assertTrue($container->hasDefinition(DoctrineSqlMiddleware::class));
        self::assertTrue($container->hasDefinition(TraceableBeaconHttpClient::class));
        if (class_exists(AbstractProcessingHandler::class)) {
            self::assertTrue($container->hasDefinition(BeaconMonologHandler::class));
        }
    }

    public function testPrependSkipsWithoutMonologExtension(): void
    {
        $container = $this->createContainer();
        $extension = new NowoBeaconExtension();
        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('monolog'));
    }

    public function testPrependSkipsWhenMonologHandlerDisabled(): void
    {
        $container = $this->createContainer();
        $this->registerMonologExtension($container);
        $container->prependExtensionConfig('nowo_beacon', [
            'monolog_handler' => ['enabled' => false],
        ]);

        (new NowoBeaconExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('monolog'));
    }

    public function testPrependWiresMonologHandlerWhenEnabled(): void
    {
        $container = $this->createContainer();
        $this->registerMonologExtension($container);
        $container->registerExtension(new NowoBeaconExtension());
        $container->prependExtensionConfig('nowo_beacon', [
            'monolog_handler' => ['enabled' => true, 'level' => 'warning'],
        ]);

        (new NowoBeaconExtension())->prepend($container);

        $monolog = $container->getExtensionConfig('monolog');
        self::assertNotEmpty($monolog);
        self::assertSame('service', $monolog[0]['handlers']['nowo_beacon']['type']);
        self::assertSame(BeaconMonologHandler::class, $monolog[0]['handlers']['nowo_beacon']['id']);
        self::assertSame('warning', $monolog[0]['handlers']['nowo_beacon']['level']);
    }

    public function testPrependWiresMessengerTraceMiddlewareWhenFrameworkPresent(): void
    {
        $container = $this->createContainer();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'framework';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });

        (new NowoBeaconExtension())->prepend($container);

        $framework = $container->getExtensionConfig('framework');
        self::assertNotEmpty($framework);
        self::assertSame(
            BeaconTraceMiddleware::class,
            $framework[0]['messenger']['buses']['messenger.bus.default']['middleware'][0],
        );
    }

    public function testRegisterNullClientRemovesOptionalListenersWhenPresent(): void
    {
        $container = $this->createContainer();
        $container->setDefinition(BeaconMessengerFailedListener::class, new Definition(BeaconMessengerFailedListener::class));
        $container->setDefinition(BeaconRequestTransactionListener::class, new Definition(BeaconRequestTransactionListener::class));

        $method = new ReflectionMethod(NowoBeaconExtension::class, 'registerNullClient');
        $method->setAccessible(true);
        $method->invoke(new NowoBeaconExtension(), $container);

        self::assertFalse($container->hasDefinition(BeaconMessengerFailedListener::class));
        self::assertFalse($container->hasDefinition(BeaconRequestTransactionListener::class));
        self::assertSame(NullBeaconClient::class, (string) $container->getAlias(BeaconClientInterface::class));
    }

    public function testMessengerObservabilityIsOptIn(): void
    {
        $container = $this->createContainer();
        (new NowoBeaconExtension())->load([['dsn' => 'https://pub:secret@beacon.example.com/1']], $container);

        self::assertFalse($container->hasDefinition(BeaconMessengerTransactionListener::class));
        self::assertFalse($container->hasDefinition(BeaconMessengerRetryListener::class));
        self::assertFalse($container->hasDefinition(BeaconWorkerMemoryListener::class));
        self::assertTrue($container->hasDefinition(MessengerReportCommand::class));
        self::assertSame(['failed'], $container->getDefinition(MessengerReportCommand::class)->getArgument('$failureTransports'));
        self::assertTrue($container->hasDefinition(DeployCommand::class));
    }

    public function testMessengerObservabilityWiresListenersWhenEnabled(): void
    {
        $container = $this->createContainer();
        (new NowoBeaconExtension())->load([[
            'dsn'       => 'https://pub:secret@beacon.example.com/1',
            'messenger' => [
                'transactions'                => true,
                'transaction_sample_rate'     => 0.25,
                'report_recovered'            => true,
                'retry_sample_rate'           => 0.5,
                'worker_memory_warning_ratio' => 0.9,
                'failure_transports'          => ['failed', 'failed_high'],
                'ignore_message_classes'      => ['App\\Message\\PingMessage'],
            ],
        ]], $container);

        $tx = $container->getDefinition(BeaconMessengerTransactionListener::class);
        self::assertSame(0.25, $tx->getArgument('$sampleRate'));
        self::assertSame(['App\\Message\\PingMessage'], $tx->getArgument('$ignoreMessageClasses'));
        self::assertTrue($tx->hasTag('kernel.reset'));

        $retry = $container->getDefinition(BeaconMessengerRetryListener::class);
        self::assertFalse($retry->getArgument('$reportRetries'));
        self::assertTrue($retry->getArgument('$reportRecovered'));
        self::assertSame(0.5, $retry->getArgument('$sampleRate'));
        self::assertSame(['App\\Message\\PingMessage'], $retry->getArgument('$ignoreMessageClasses'));
        self::assertSame(
            ['App\\Message\\PingMessage'],
            $container->getDefinition(BeaconMessengerFailedListener::class)->getArgument('$ignoreMessageClasses'),
        );

        self::assertSame(0.9, $container->getDefinition(BeaconWorkerMemoryListener::class)->getArgument('$ratio'));
        self::assertSame(
            ['failed', 'failed_high'],
            $container->getDefinition(MessengerReportCommand::class)->getArgument('$failureTransports'),
        );
    }

    public function testMessengerReportCommandIsRegisteredWithNullClient(): void
    {
        $container = $this->createContainer();
        (new NowoBeaconExtension())->load([[
            'dsn'       => '',
            'messenger' => ['transactions' => true, 'report_retries' => true],
        ]], $container);

        self::assertTrue($container->hasDefinition(MessengerReportCommand::class));
        self::assertFalse($container->hasDefinition(BeaconMessengerTransactionListener::class));
        self::assertFalse($container->hasDefinition(BeaconMessengerRetryListener::class));
    }

    public function testSelfTestServicesAreRegisteredWithNullClient(): void
    {
        $container = $this->createContainer();
        (new NowoBeaconExtension())->load([['dsn' => '']], $container);

        $service = $container->getDefinition(BeaconSelfTest::class);
        self::assertFalse($service->getArgument('$reportingEnabled'));
        self::assertEquals(new Reference(BeaconClientInterface::class), $service->getArgument('$client'));
        self::assertEquals(new Reference('messenger.default_bus', ContainerBuilder::NULL_ON_INVALID_REFERENCE), $service->getArgument('$bus'));
        self::assertTrue($container->getDefinition(SelfTestCommand::class)->hasTag('console.command'));
        self::assertSame(
            [['handles' => BeaconSelfTestFailMessage::class]],
            $container->getDefinition(BeaconSelfTestFailHandler::class)->getTag('messenger.message_handler'),
        );

        $loader = $container->getDefinition(BeaconRouteLoader::class);
        self::assertTrue($loader->hasTag('routing.loader'));
        self::assertFalse($loader->getArgument('$selfTestEnabled'));
        self::assertFalse($container->hasDefinition(SelfTestController::class));
    }

    public function testSelfTestHttpControllerWhenEnabled(): void
    {
        $container = $this->createContainer();
        (new NowoBeaconExtension())->load([[
            'dsn'                          => 'https://pubkey:secret@beacon.example.com/5',
            'register_console_listener'    => false,
            'register_php_warning_handler' => false,
            'self_test'                    => ['http' => ['enabled' => true, 'path' => '/admin/beacon-selftest', 'roles' => ['ROLE_OPS']]],
        ]], $container);

        $service = $container->getDefinition(BeaconSelfTest::class);
        self::assertTrue($service->getArgument('$reportingEnabled'));
        self::assertFalse($service->getArgument('$consoleListener'));
        self::assertFalse($service->getArgument('$phpWarningHandler'));
        self::assertTrue($service->getArgument('$exceptionListener'));

        $loader = $container->getDefinition(BeaconRouteLoader::class);
        self::assertTrue($loader->getArgument('$selfTestEnabled'));
        self::assertSame('/admin/beacon-selftest', $loader->getArgument('$selfTestPath'));

        $controller = $container->getDefinition(SelfTestController::class);
        self::assertTrue($controller->isPublic());
        self::assertTrue($controller->hasTag('controller.service_arguments'));
        self::assertSame(['ROLE_OPS'], $controller->getArgument('$roles'));
        self::assertEquals(new Reference('security.csrf.token_manager', ContainerBuilder::NULL_ON_INVALID_REFERENCE), $controller->getArgument('$csrfTokenManager'));
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        return $container;
    }

    private function registerMonologExtension(ContainerBuilder $container): void
    {
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'monolog';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
    }
}
