<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\BeaconBundle\Bridge\InstanceSettings\BeaconSettingsSection;
use Nowo\BeaconBundle\Bridge\InstanceSettings\InstanceSettingsCredentialsListener;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\DependencyInjection\Compiler\CredentialsProviderPass;
use Nowo\BeaconBundle\DependencyInjection\NowoBeaconExtension;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\EventListener\BeaconCredentialsArmListener;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ReadOnlyProvider;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;

#[CoversClass(NowoBeaconExtension::class)]
#[CoversClass(CredentialsProviderPass::class)]
#[CoversClass(BeaconCredentialsArmListener::class)]
final class CredentialsWiringTest extends TestCase
{
    public function testProviderWithEmptyConfigDsnWiresTheResolvingClient(): void
    {
        $container = $this->load(['dsn' => '', 'credentials_provider' => 'app.creds', 'credentials_cache_ttl' => 30, 'transport' => ['mode' => 'messenger'], 'messenger' => ['remote_retry' => ['enabled' => true, 'worker_key' => '']]]);

        self::assertTrue($container->getParameter('nowo.beacon.enabled'), 'the provider may supply the DSN at runtime');
        self::assertSame('app.creds', $container->getParameter('nowo.beacon.credentials_provider'));
        self::assertSame('nowo.beacon.client', (string) $container->getAlias(BeaconClientInterface::class));
        self::assertTrue($container->hasDefinition(BeaconExceptionListener::class));

        $client = $container->getDefinition('nowo.beacon.client');
        self::assertEquals([new Reference(BeaconClientFactory::class), 'createResolving'], $client->getFactory());
        self::assertEquals(new Reference(BeaconCredentialsResolver::class), $client->getArgument('$resolver'));
        self::assertArrayNotHasKey('$dsn', $client->getArguments());

        $sync = $container->getDefinition('nowo.beacon.sync_transport');
        self::assertEquals([new Reference(BeaconClientFactory::class), 'createResolvingSyncTransport'], $sync->getFactory());

        $resolver = $container->getDefinition(BeaconCredentialsResolver::class);
        self::assertEquals(new Reference('app.creds'), $resolver->getArgument('$provider'));
        self::assertSame(30, $resolver->getArgument('$cacheTtl'));
        self::assertSame([['method' => 'reset']], $resolver->getTag('kernel.reset'));

        foreach ([RemoteRetryCredentials::class => '$resolver', BeaconConnectionTester::class => '$credentials', BeaconSelfTest::class => '$credentials'] as $id => $argument) {
            self::assertEquals(new Reference(BeaconCredentialsResolver::class), $container->getDefinition($id)->getArgument($argument), $id);
        }

        self::assertTrue($container->hasDefinition(BeaconCredentialsArmListener::class));
        self::assertTrue($container->hasDefinition(RotateCredentialsCommand::class));
    }

    public function testWithoutProviderTheResolverIsInertAndNothingArms(): void
    {
        $container = $this->load(['dsn' => '']);

        self::assertFalse($container->getParameter('nowo.beacon.enabled'));
        self::assertSame(NullBeaconClient::class, (string) $container->getAlias(BeaconClientInterface::class));
        self::assertNull($container->getParameter('nowo.beacon.credentials_provider'));
        self::assertNull($container->getDefinition(BeaconCredentialsResolver::class)->getArgument('$provider'));
        self::assertFalse($container->hasDefinition(BeaconCredentialsArmListener::class));
        self::assertNull($container->getDefinition(RotateCredentialsCommand::class)->getArgument('$store'));
    }

    public function testInstanceSettingsBridgeRegistersSectionStoreAndListener(): void
    {
        $container = $this->load(['dsn' => '', 'instance_settings_bridge' => true]);

        self::assertSame(NowoBeaconExtension::INSTANCE_SETTINGS_STORE, $container->getParameter('nowo.beacon.credentials_provider'));
        self::assertTrue($container->getDefinition(BeaconSettingsSection::class)->hasTag('nowo_instance_settings.section'));
        self::assertSame([['event' => SettingsChangedEvent::class]], $container->getDefinition(InstanceSettingsCredentialsListener::class)->getTag('kernel.event_listener'));
        self::assertEquals(new Reference(NowoBeaconExtension::INSTANCE_SETTINGS_STORE), $container->getDefinition(BeaconCredentialsResolver::class)->getArgument('$provider'));

        $explicit = $this->load(['dsn' => '', 'instance_settings_bridge' => true, 'credentials_provider' => 'app.creds']);
        self::assertSame('app.creds', $explicit->getParameter('nowo.beacon.credentials_provider'), 'an explicit provider wins');
        self::assertTrue($explicit->hasDefinition(BeaconSettingsSection::class));
    }

    public function testInvalidProviderIdsAreRejected(): void
    {
        foreach (['', '  ', '@app.creds'] as $id) {
            try {
                $this->load(['credentials_provider' => $id]);
                self::fail('Expected InvalidConfigurationException for "' . $id . '"');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('credentials_provider must be a service id', $e->getMessage());
            }
        }
    }

    public function testPassChecksTheProviderAndWiresAWritableOneIntoTheRotateCommand(): void
    {
        $container = $this->load(['dsn' => '', 'credentials_provider' => 'app.creds']);
        $container->setDefinition('app.creds', new Definition(InMemoryCredentialsStore::class));
        (new CredentialsProviderPass())->process($container);
        self::assertEquals(new Reference('app.creds'), $container->getDefinition(RotateCredentialsCommand::class)->getArgument('$store'));

        $readOnly = $this->load(['dsn' => '', 'credentials_provider' => 'app.creds']);
        $readOnly->setParameter('creds.class', ReadOnlyProvider::class);
        $readOnly->setDefinition('app.creds', new Definition('%creds.class%'));
        (new CredentialsProviderPass())->process($readOnly);
        self::assertNull($readOnly->getDefinition(RotateCredentialsCommand::class)->getArgument('$store'));

        $factoryBuilt = $this->load(['dsn' => '', 'credentials_provider' => 'app.creds']);
        $factoryBuilt->setDefinition('app.creds', (new Definition())->setFactory(['App\\Factory', 'create']));
        (new CredentialsProviderPass())->process($factoryBuilt);
        self::assertNull($factoryBuilt->getDefinition(RotateCredentialsCommand::class)->getArgument('$store'));

        $none = new ContainerBuilder();
        (new CredentialsProviderPass())->process($none);
        $none->setParameter('nowo.beacon.credentials_provider', null);
        (new CredentialsProviderPass())->process($none);
        self::assertFalse($none->hasDefinition(RotateCredentialsCommand::class));
    }

    public function testPassRejectsMissingOrForeignServices(): void
    {
        $missing = $this->load(['dsn' => '', 'credentials_provider' => 'app.creds']);
        try {
            (new CredentialsProviderPass())->process($missing);
            self::fail('Expected LogicException');
        } catch (LogicException $e) {
            self::assertStringContainsString('service "app.creds" does not exist', $e->getMessage());
        }

        $foreign = $this->load(['dsn' => '', 'credentials_provider' => 'app.creds']);
        $foreign->setDefinition('app.creds', new Definition(ScriptedIngest::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement');
        (new CredentialsProviderPass())->process($foreign);
    }

    public function testArmListenerArmsTheResolverOnEveryEntryPoint(): void
    {
        $store    = new InMemoryCredentialsStore();
        $resolver = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', $store, 0);
        $resolver->resolve();
        self::assertSame(0, $store->reads);

        $listener = new BeaconCredentialsArmListener($resolver);
        self::assertSame(
            ['kernel.request', 'console.command', 'Symfony\\Component\\Messenger\\Event\\WorkerStartedEvent'],
            array_keys(BeaconCredentialsArmListener::getSubscribedEvents()),
        );
        $listener->arm();
        $resolver->resolve();
        self::assertSame(1, $store->reads);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoBeaconExtension())->load([$config], $container);

        return $container;
    }
}
