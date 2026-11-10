<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Kernel;

use Nowo\BeaconBundle\NowoBeaconBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Host app with Messenger enabled and one or more `nowo_beacon` config blocks (merged in order, like a base file
 * followed by a `when@test` override).
 */
final class MessengerKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param list<array<string, mixed>> $beaconConfigs
     * @param list<string> $extraBuses Further bus names declared by the host (besides messenger.bus.default)
     */
    public function __construct(private readonly array $beaconConfigs, string $environment = 'test', private readonly array $extraBuses = [])
    {
        parent::__construct($environment, false);
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
        return self::baseDir() . '/' . md5(serialize([$this->beaconConfigs, $this->extraBuses])) . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . md5(serialize([$this->beaconConfigs, $this->extraBuses])) . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-beacon-messenger-kernel';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $buses = ['messenger.bus.default' => ['default_middleware' => ['enabled' => true, 'allow_no_handlers' => true]]];
        foreach ($this->extraBuses as $bus) {
            $buses[$bus] = ['default_middleware' => ['enabled' => true, 'allow_no_handlers' => true]];
        }

        $container->extension('framework', [
            'secret'               => 'test-only-not-a-secret',
            'test'                 => true,
            'http_method_override' => false,
            'messenger'            => [
                'default_bus' => 'messenger.bus.default',
                'buses'       => $buses,
            ],
        ]);
        foreach ($this->beaconConfigs as $beaconConfig) {
            $container->extension('nowo_beacon', $beaconConfig);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
