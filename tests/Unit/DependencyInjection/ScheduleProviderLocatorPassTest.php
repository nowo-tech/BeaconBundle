<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\DependencyInjection;

use Nowo\BeaconBundle\DependencyInjection\Compiler\ScheduleProviderLocatorPass;
use Nowo\BeaconBundle\NowoBeaconBundle;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\FixedScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Scheduler\Schedule;

final class ScheduleProviderLocatorPassTest extends TestCase
{
    public function testCollectsScheduleProvidersByName(): void
    {
        $container = new ContainerBuilder();
        (new NowoBeaconBundle())->build($container);

        $container->setDefinition(CheckInPolicy::class, new Definition(CheckInPolicy::class));
        $container->setDefinition(ScheduleCatalogBuilder::class, (new Definition(ScheduleCatalogBuilder::class, [
            '$policy'    => $container->getDefinition(CheckInPolicy::class),
            '$providers' => null,
        ]))->setPublic(true));

        $schedule = new Definition(Schedule::class);
        foreach (['reports' => 'app.reports_provider', 'default' => 'app.default_provider'] as $name => $id) {
            $container->setDefinition($id, (new Definition(FixedScheduleProvider::class, [$schedule]))
                ->addTag(ScheduleProviderLocatorPass::TAG, ['name' => $name])
                ->addTag(ScheduleProviderLocatorPass::TAG, ['name' => $name]));
        }
        $container->setDefinition('app.unnamed_provider', (new Definition(FixedScheduleProvider::class, [$schedule]))
            ->addTag(ScheduleProviderLocatorPass::TAG));

        $container->compile(true);

        $builder = $container->get(ScheduleCatalogBuilder::class);
        self::assertInstanceOf(ScheduleCatalogBuilder::class, $builder);
        self::assertSame(['default', 'reports'], $builder->scheduleNames());
    }

    public function testNoopWithoutCatalogBuilder(): void
    {
        $container = new ContainerBuilder();
        (new ScheduleProviderLocatorPass())->process($container);

        self::assertFalse($container->hasDefinition(ScheduleCatalogBuilder::class));
    }
}
