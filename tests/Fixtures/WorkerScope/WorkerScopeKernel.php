<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\WorkerScope;

use Nowo\BeaconBundle\NowoBeaconBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * Host app of the worker-scope test (REQ-RUNTIME-002): one route, one Messenger handler, the bundle enabled with an
 * obviously fake DSN, a `before_send` recorder and a mocked ingest HTTP client (nothing leaves the process).
 */
final class WorkerScopeKernel extends Kernel implements CompilerPassInterface
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
        return sys_get_temp_dir() . '/nowo-beacon-worker-scope-kernel';
    }

    /**
     * Replaces the ingest HTTP clients with a mock that accepts every Envelope.
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (['nowo.beacon.ingest_http_client', 'nowo.beacon.ingest_http_client.sync'] as $id) {
            // No factory: every request gets a fresh empty 200 MockResponse.
            $container->setDefinition($id, new Definition(MockHttpClient::class));
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
        $container->extension('nowo_beacon', [
            'dsn'         => 'https://pubkey:secret@beacon.example.invalid/1',
            'before_send' => RecordingBeforeSend::class,
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(RecordingBeforeSend::class)->public();
        $services->set(ScopeProbeHandler::class)
            ->autowire()
            ->tag('messenger.message_handler', ['handles' => ScopeProbeMessage::class]);
        $services->set(ScopeProbeController::class)
            ->autowire()
            ->public()
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('probe', '/probe/{user}/{iteration}/{capture}')->controller(ScopeProbeController::class);
    }
}
