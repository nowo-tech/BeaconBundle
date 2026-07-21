<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\DependencyInjection\Configuration;
use Nowo\BeaconBundle\DependencyInjection\NowoBeaconExtension;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        return $container;
    }
}
