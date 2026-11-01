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
     */
    public function __construct(private readonly array $beaconConfigs, string $environment = 'test')
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
        return self::baseDir() . '/' . md5(serialize($this->beaconConfigs)) . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . md5(serialize($this->beaconConfigs)) . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-beacon-messenger-kernel';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'test-only-not-a-secret',
            'test'                 => true,
            'http_method_override' => false,
            'messenger'            => [
                'buses' => [
                    'messenger.bus.default' => [
                        'default_middleware' => ['enabled' => true, 'allow_no_handlers' => true],
                    ],
                ],
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
