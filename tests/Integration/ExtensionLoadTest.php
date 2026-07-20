<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\DependencyInjection\Configuration;
use Nowo\BeaconBundle\DependencyInjection\NowoBeaconExtension;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
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
            'dsn'     => 'https://pubkey@beacon.example.com/5',
        ]], $container);

        self::assertFalse($container->getParameter('nowo.beacon.enabled'));
        self::assertSame(NullBeaconClient::class, (string) $container->getAlias(BeaconClientInterface::class));
        self::assertFalse($container->hasDefinition(BeaconExceptionListener::class));
    }

    public function testValidDsnWiresBeaconClientServices(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'           => true,
            'dsn'               => 'https://pubkey@beacon.example.com:9444/5',
            'environment'       => 'test',
            'release'           => '1.0.0',
            'server_name'       => 'ci-host',
            'verify_peer'       => false,
            'timeout'           => 1.5,
            'ignore_exceptions' => [RuntimeException::class],
        ]], $container);

        self::assertTrue($container->getParameter('nowo.beacon.enabled'));
        self::assertSame('https://pubkey@beacon.example.com:9444/5', $container->getParameter('nowo.beacon.dsn'));
        self::assertSame(BeaconClient::class, (string) $container->getAlias(BeaconClientInterface::class));
        self::assertTrue($container->hasDefinition(BeaconDsn::class));
        self::assertTrue($container->hasDefinition(EnvelopeBuilder::class));
        self::assertTrue($container->hasDefinition(EnvelopeTransport::class));
        self::assertTrue($container->hasDefinition(BeaconClient::class));
        self::assertTrue($container->hasDefinition(BeaconExceptionListener::class));

        $transportDefinition = $container->getDefinition(EnvelopeTransport::class);
        self::assertSame(1.5, $transportDefinition->getArgument('$timeout'));
        self::assertFalse($transportDefinition->getArgument('$verifyPeer'));
        self::assertEquals(new Reference(BeaconDsn::class), $transportDefinition->getArgument('$dsn'));

        $listenerDefinition = $container->getDefinition(BeaconExceptionListener::class);
        self::assertEquals(new Reference(BeaconClientInterface::class), $listenerDefinition->getArgument('$client'));
        self::assertTrue($listenerDefinition->getArgument('$enabled'));
        self::assertSame([RuntimeException::class], $listenerDefinition->getArgument('$ignoreExceptions'));
    }

    public function testRegisterErrorListenerFalseRemovesListener(): void
    {
        $container = $this->createContainer();

        (new NowoBeaconExtension())->load([[
            'enabled'                 => true,
            'dsn'                     => 'https://pubkey@beacon.example.com/5',
            'register_error_listener' => false,
        ]], $container);

        self::assertFalse($container->hasDefinition(BeaconExceptionListener::class));
        self::assertSame(BeaconClient::class, (string) $container->getAlias(BeaconClientInterface::class));
    }

    public function testInvalidDsnThrows(): void
    {
        $this->expectException(InvalidBeaconDsnException::class);

        $container = $this->createContainer();
        (new NowoBeaconExtension())->load([[
            'enabled' => true,
            'dsn'     => 'https://pubkey@beacon.example.com/not-a-number',
        ]], $container);
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        return $container;
    }
}
