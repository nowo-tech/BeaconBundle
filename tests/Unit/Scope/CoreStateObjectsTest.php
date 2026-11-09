<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Scope;

use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer as CoreBreadcrumbBuffer;
use Nowo\Beacon\Scope\Scope as CoreScope;
use Nowo\Beacon\Span\SpanBuffer as CoreSpanBuffer;
use Nowo\Beacon\Trace\TraceIdProvider as CoreTraceIdProvider;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The bundle's state services are beacon-php's classes plus Symfony's ResetInterface (reset between requests / jobs).
 */
final class CoreStateObjectsTest extends TestCase
{
    public function testStateObjectsExtendTheCoreClassesAndReset(): void
    {
        $scope = new Scope();
        $scope->setTag('k', 'v');
        $scope->setUser(['id' => '1']);
        $scope->setExtra('e', 1);

        $crumbs = new BreadcrumbBuffer();
        $crumbs->add('crumb');

        $spans = new SpanBuffer();
        $spans->addTimed('op', 'description', 0.5);

        $trace = new TraceIdProvider();
        $trace->set('trace-id-0001');

        foreach ([Scope::class => CoreScope::class, BreadcrumbBuffer::class => CoreBreadcrumbBuffer::class, SpanBuffer::class => CoreSpanBuffer::class, TraceIdProvider::class => CoreTraceIdProvider::class] as $bundleClass => $coreClass) {
            self::assertTrue((new ReflectionClass($bundleClass))->isSubclassOf($coreClass), $bundleClass);
            self::assertTrue((new ReflectionClass($bundleClass))->implementsInterface(ResetInterface::class), $bundleClass);
        }

        foreach ([$scope, $crumbs, $spans, $trace] as $service) {
            $service->reset();
        }

        self::assertSame([], $scope->getTags());
        self::assertNull($scope->getUser());
        self::assertSame([], $scope->getExtra());
        self::assertSame([], $crumbs->all());
        self::assertSame([], $spans->all());
        self::assertNull($trace->get());
    }
}
