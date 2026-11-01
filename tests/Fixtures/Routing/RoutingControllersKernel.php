<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Routing;

use Nowo\BeaconBundle\NowoBeaconBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * Minimal host app with the self-test endpoint enabled whose routing is the Symfony skeleton default
 * (`routing.controllers`) and, optionally, the documented `type: nowo_beacon` import (REQ-SF-004 rule 7).
 */
final class RoutingControllersKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly bool $importBeaconRoutes)
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
        return sys_get_temp_dir() . '/nowo-beacon-routing-' . ($this->importBeaconRoutes ? 'import' : 'controllers') . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-beacon-routing-' . ($this->importBeaconRoutes ? 'import' : 'controllers') . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'test-only-not-a-secret',
            'test'                 => true,
            'http_method_override' => false,
            'router'               => ['utf8' => true],
        ]);
        $container->extension('nowo_beacon', [
            // A literal DSN: no request is sent while the kernel boots or the routes load.
            'dsn'       => 'https://pubkey:secret@beacon.example.invalid/1',
            'self_test' => ['http' => ['enabled' => true]],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
        if ($this->importBeaconRoutes) {
            $routes->import('.', 'nowo_beacon');
        }
    }
}
