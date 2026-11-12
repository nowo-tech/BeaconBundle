<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\CycleKernel;

use Monolog\Logger;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Nowo\BeaconBundle\NowoBeaconBundle;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use Nowo\BeaconBundle\Tests\Fixtures\CredentialsKernel\ReportController;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
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

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * Beacon's 1.25.1 setup (issue reported on integration): `instance_settings_bridge: true` and the Beacon Monolog
 * handler, with an InstanceSettings service that needs the logger. The logger gets its handler by `pushHandler()` as
 * MonologBundle wires it. Chain: Beacon client → resolver → InstanceSettings store → InstanceSettings → logger →
 * Beacon handler → Beacon client.
 */
final class CycleKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public function __construct()
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
        return self::baseDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-beacon-cycle-kernel';
    }

    public function process(ContainerBuilder $container): void
    {
        foreach (['nowo.beacon.ingest_http_client', 'nowo.beacon.ingest_http_client.sync'] as $id) {
            $client = new Definition(MockHttpClient::class);
            $client->setFactory([new Reference(ScriptedIngest::class), 'client']);
            $container->setDefinition($id, $client);
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'test-only-not-a-secret',
            'test'                 => true,
            'http_method_override' => false,
        ]);
        $container->extension('nowo_beacon', [
            'dsn'                      => CredentialsFixtures::CONFIG_DSN,
            'instance_settings_bridge' => true,
            'credentials_cache_ttl'    => 0,
            'monolog_handler'          => ['enabled' => true, 'level' => 'error'],
            // Nothing built at boot: the first service the test asks for is the entry point of the chain.
            'register_fatal_handler'       => false,
            'register_php_warning_handler' => false,
        ]);

        $services = $container->services();
        $services->set(ScriptedIngest::class)->public()->args([['fixture-secret-env', 'fixture-secret-db']]);
        $services->set('logger', Logger::class)->args(['app'])->call('pushHandler', [service(BeaconMonologHandler::class)])->public();
        $services->set(InstanceSettingsInterface::class, LoggingInstanceSettings::class)->args([service('logger')])->public();
        $services->set(ReportController::class)->autowire()->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('report', '/report/{iteration}')->controller(ReportController::class);
    }
}
