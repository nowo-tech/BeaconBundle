<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\CredentialsKernel;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\NowoBeaconBundle;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\FakeInstanceSettings;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function md5;
use function serialize;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * Host app of the credentials tests.
 *
 * - `ddd`: the host's own plain-PHP adapter (`app.beacon_credentials`, {@see InMemoryCredentialsStore}) as
 *   `credentials_provider`, no InstanceSettings, no Doctrine.
 * - `standard`: `instance_settings_bridge: true` over an in-memory InstanceSettings service.
 * - `none`: no provider (as 1.24).
 *
 * The ingest HTTP clients are replaced by {@see ScriptedIngest} (nothing leaves the process).
 */
final class CredentialsKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public const PUBLIC_IDS = [
        BeaconClientInterface::class,
        BeaconCredentialsResolver::class,
        RotateCredentialsCommand::class,
        ScriptedIngest::class,
        'app.beacon_credentials',
        InstanceSettingsInterface::class,
    ];

    /**
     * @param 'ddd'|'none'|'standard' $host
     * @param array<string, mixed> $beacon extra nowo_beacon configuration
     */
    public function __construct(private readonly string $host, private readonly array $beacon = [])
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new NowoBeaconBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . md5(serialize([$this->host, $this->beacon])) . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . md5(serialize([$this->host, $this->beacon])) . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-beacon-credentials-kernel';
    }

    public function process(ContainerBuilder $container): void
    {
        foreach (['nowo.beacon.ingest_http_client', 'nowo.beacon.ingest_http_client.sync'] as $id) {
            if ($container->hasDefinition($id)) {
                $client = new Definition(MockHttpClient::class);
                $client->setFactory([new Reference(ScriptedIngest::class), 'client']);
                $container->setDefinition($id, $client);
            }
        }

        // The connection tester (nowo:beacon:test, rotation self-test) POSTs through the scripted ingest too.
        if ($container->hasDefinition(BeaconConnectionTester::class)) {
            $container->getDefinition(BeaconConnectionTester::class)->setArgument('$httpClient', (new Definition(MockHttpClient::class))->setFactory([new Reference(ScriptedIngest::class), 'client']));
        }

        foreach (self::PUBLIC_IDS as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'test-only-not-a-secret',
            'test'                 => true,
            'http_method_override' => false,
            'messenger'            => [
                'default_bus' => 'messenger.bus.default',
                'buses'       => ['messenger.bus.default' => []],
            ],
        ]);

        $beacon = ['dsn' => '', 'credentials_cache_ttl' => 0, 'credentials_allowed_origins' => ['https://beacon.example.invalid'], ...$this->beacon];
        if ($this->host === 'ddd') {
            $beacon['credentials_provider'] = 'app.beacon_credentials';
        } elseif ($this->host === 'standard') {
            $beacon['instance_settings_bridge'] = true;
        }

        $container->extension('nowo_beacon', $beacon);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(ScriptedIngest::class)->args([['fixture-secret-db', 'fixture-secret-next', 'fixture-secret-two', 'fixture-secret-env']]);
        $services->set('app.beacon_credentials', InMemoryCredentialsStore::class);
        if ($this->host === 'standard') {
            $services->set(InstanceSettingsInterface::class, FakeInstanceSettings::class)->args([service('event_dispatcher')]);
        }

        $services->set(ReportController::class)
            ->autowire()
            ->public()
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('report', '/report/{iteration}')->controller(ReportController::class);
    }
}
