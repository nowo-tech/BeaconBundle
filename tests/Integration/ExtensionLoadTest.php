<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Monolog\Handler\AbstractProcessingHandler;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\DependencyInjection\Configuration;
use Nowo\BeaconBundle\DependencyInjection\NowoBeaconExtension;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Envelope\SendBeaconEnvelopeMessageHandler;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconRequestTransactionListener;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use Nowo\BeaconBundle\Instrumentation\DoctrineSqlMiddleware;
use Nowo\BeaconBundle\Instrumentation\TraceableBeaconHttpClient;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

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
        self::assertArrayHasKey('user', $clientDefinition->getArgument('$send'));
        self::assertFalse($clientDefinition->getArgument('$send')['user']);
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
            'auto_http_transaction'       => true,
            'monolog_handler'             => ['enabled' => true, 'level' => 'warning'],
            'instrumentation'             => [
                'doctrine'    => true,
                'http_client' => true,
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(BeaconConsoleErrorListener::class));
        self::assertTrue($container->hasDefinition(BeaconMessengerFailedListener::class));
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
