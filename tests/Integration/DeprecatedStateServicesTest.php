<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer as CoreBreadcrumbBuffer;
use Nowo\Beacon\Scope\Scope as CoreScope;
use Nowo\Beacon\Span\SpanBuffer as CoreSpanBuffer;
use Nowo\Beacon\Trace\TraceIdProvider as CoreTraceIdProvider;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\DependencyInjection\NowoBeaconExtension;
use Nowo\BeaconBundle\EventListener\BeaconRequestScopeResetListener;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.27: the request-scoped state services are beacon-php's classes (tagged `kernel.reset`, method `reset`); the former
 * bundle service ids stay as deprecated wrappers around the same instances.
 */
final class DeprecatedStateServicesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function services(): iterable
    {
        yield 'breadcrumbs' => [CoreBreadcrumbBuffer::class, BreadcrumbBuffer::class];
        yield 'scope' => [CoreScope::class, Scope::class];
        yield 'spans' => [CoreSpanBuffer::class, SpanBuffer::class];
        yield 'trace' => [CoreTraceIdProvider::class, TraceIdProvider::class];
    }

    /**
     * @param class-string $coreId
     * @param class-string $bundleId
     */
    #[DataProvider('services')]
    public function testCoreServiceIsResetAndTheBundleIdWrapsTheSameInstance(string $coreId, string $bundleId): void
    {
        $loaded = $this->load();

        self::assertSame([['method' => 'reset']], $loaded->getDefinition($coreId)->getTag('kernel.reset'));
        self::assertFalse($loaded->getDefinition($bundleId)->hasTag('kernel.reset'));

        // Compile only the two definitions (the rest of the bundle needs framework services).
        $container = new ContainerBuilder();
        $container->setDefinition($coreId, $loaded->getDefinition($coreId)->setPublic(true));
        $container->setDefinition($bundleId, $loaded->getDefinition($bundleId)->setPublic(true));
        $container->compile(true);

        $core    = $container->get($coreId);
        $wrapper = @$container->get($bundleId);
        self::assertInstanceOf($coreId, $core);
        self::assertTrue($wrapper instanceof BreadcrumbBuffer || $wrapper instanceof Scope || $wrapper instanceof SpanBuffer || $wrapper instanceof TraceIdProvider);
        self::assertInstanceOf($bundleId, $wrapper);
        self::assertSame($core, $wrapper->getInner());
    }

    public function testTheResetListenerClearsTheCoreServices(): void
    {
        $scopeState = $this->load()->getDefinition(BeaconRequestScopeResetListener::class)->getArgument('$scopeState');
        self::assertInstanceOf(IteratorArgument::class, $scopeState);

        $ids = [];
        foreach ($scopeState->getValues() as $reference) {
            self::assertInstanceOf(Reference::class, $reference);
            $ids[] = (string) $reference;
        }

        self::assertSame([CoreBreadcrumbBuffer::class, CoreSpanBuffer::class, CoreScope::class, CoreTraceIdProvider::class], $ids);
    }

    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoBeaconExtension())->load([['dsn' => 'https://pubkey:secret@beacon.example.com/5']], $container);

        return $container;
    }
}
