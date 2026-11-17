<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Scope;

use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer as CoreBreadcrumbBuffer;
use Nowo\Beacon\Scope\Scope as CoreScope;
use Nowo\Beacon\Span\SpanBuffer as CoreSpanBuffer;
use Nowo\Beacon\Trace\TraceIdProvider as CoreTraceIdProvider;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\EventListener\BeaconTraceRequestListener;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

use function constant;
use function restore_error_handler;
use function set_error_handler;
use function strlen;

use const E_USER_DEPRECATED;

/**
 * Since 1.27 the bundle's state classes are deprecated stateless wrappers around beacon-php's final classes.
 */
final class CoreStateObjectsTest extends TestCase
{
    public function testWrappersDelegateEveryMethodToTheCoreInstance(): void
    {
        $coreScope = new CoreScope();
        $scope     = $this->silently(static fn (): Scope => new Scope($coreScope));
        self::assertSame($coreScope, $scope->getInner());
        $scope->setTag('k', 'v');
        $scope->setTags(['a' => 1, 'b' => 2]);
        $scope->removeTag('a');
        $scope->setUser(['id' => '1']);
        $scope->setExtra('e', 1);
        self::assertSame(['k' => 'v', 'b' => '2'], $coreScope->getTags());
        self::assertSame($coreScope->getTags(), $scope->getTags());
        self::assertSame(['id' => '1'], $scope->getUser());
        self::assertSame(['e' => 1], $scope->getExtra());
        $scope->clearTags();
        self::assertSame([], $coreScope->getTags());

        $coreCrumbs = new CoreBreadcrumbBuffer();
        $crumbs     = $this->silently(static fn (): BreadcrumbBuffer => new BreadcrumbBuffer($coreCrumbs));
        self::assertSame($coreCrumbs, $crumbs->getInner());
        $crumbs->add('crumb', 'cat', 'warning', ['x' => 1], 'http');
        self::assertSame($coreCrumbs->all(), $crumbs->all());
        self::assertSame('crumb', $crumbs->all()[0]['message']);
        $crumbs->clear();
        self::assertSame([], $coreCrumbs->all());

        $coreSpans = new CoreSpanBuffer();
        $spans     = $this->silently(static fn (): SpanBuffer => new SpanBuffer($coreSpans));
        self::assertSame($coreSpans, $spans->getInner());
        $spans->add('db', 'SELECT 1', 1.0, 2.0, ['rows' => 1]);
        $spans->addTimed('http', 'GET /', 0.5, [], 10.0);
        self::assertSame($coreSpans->all(), $spans->all());
        self::assertCount(2, $spans->drain());
        self::assertSame([], $coreSpans->all());
        $spans->add('db', 'SELECT 2', 1.0, 2.0);
        $spans->clear();
        self::assertSame([], $coreSpans->all());

        $coreTrace = new CoreTraceIdProvider();
        $trace     = $this->silently(static fn (): TraceIdProvider => new TraceIdProvider($coreTrace));
        self::assertSame($coreTrace, $trace->getInner());
        self::assertNull($trace->get());
        $trace->set('trace-id-0001');
        self::assertSame('trace-id-0001', $coreTrace->get());
        self::assertSame('trace-id-0001', $trace->getOrCreate());
        self::assertSame(32, strlen(TraceIdProvider::generate()));
        self::assertSame('trace-id-0002', TraceIdProvider::normalize(' trace-id-0002 '));
        self::assertNull(TraceIdProvider::fromMixed(42));

        foreach ([$scope, $crumbs, $spans, $trace] as $service) {
            self::assertInstanceOf(ResetInterface::class, $service);
        }

        $scope->setTag('k', 'v');
        $crumbs->add('crumb');
        $spans->addTimed('op', 'description', 0.5);
        foreach ([$scope, $crumbs, $spans, $trace] as $service) {
            $service->reset();
        }

        self::assertSame([], $coreScope->getTags());
        self::assertNull($coreScope->getUser());
        self::assertSame([], $coreScope->getExtra());
        self::assertSame([], $coreCrumbs->all());
        self::assertSame([], $coreSpans->all());
        self::assertNull($coreTrace->get());
    }

    public function testConstantsKeepTheirValues(): void
    {
        $pairs = [
            [CoreScope::class, Scope::class, ['MAX_TAGS', 'MAX_KEY_LENGTH', 'MAX_VALUE_LENGTH', 'MAX_EXTRA']],
            [CoreBreadcrumbBuffer::class, BreadcrumbBuffer::class, ['MAX_ITEMS']],
            [CoreSpanBuffer::class, SpanBuffer::class, ['MAX_ITEMS']],
            [CoreTraceIdProvider::class, TraceIdProvider::class, ['HEADER']],
        ];
        foreach ($pairs as [$core, $wrapper, $names]) {
            foreach ($names as $name) {
                self::assertSame(constant($core . '::' . $name), constant($wrapper . '::' . $name), $wrapper . '::' . $name);
            }
        }

        self::assertSame('_beacon_trace_id', constant(BeaconTraceRequestListener::class . '::REQUEST_ATTRIBUTE'));
        self::assertSame('_beacon_trace_id', constant(TraceIdProvider::class . '::REQUEST_ATTRIBUTE'));
    }

    public function testEachWrapperIsDeprecatedAndWrapsAFreshCoreInstanceByDefault(): void
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $wrappers = [new Scope(), new BreadcrumbBuffer(), new SpanBuffer(), new TraceIdProvider()];
        } finally {
            restore_error_handler();
        }

        self::assertSame([
            'Since nowo-tech/beacon-bundle 1.27.0: The "Nowo\BeaconBundle\Scope\Scope" class is deprecated, type-hint "Nowo\Beacon\Scope\Scope" instead.',
            'Since nowo-tech/beacon-bundle 1.27.0: The "Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer" class is deprecated, type-hint "Nowo\Beacon\Breadcrumb\BreadcrumbBuffer" instead.',
            'Since nowo-tech/beacon-bundle 1.27.0: The "Nowo\BeaconBundle\Instrumentation\SpanBuffer" class is deprecated, type-hint "Nowo\Beacon\Span\SpanBuffer" instead.',
            'Since nowo-tech/beacon-bundle 1.27.0: The "Nowo\BeaconBundle\Trace\TraceIdProvider" class is deprecated, type-hint "Nowo\Beacon\Trace\TraceIdProvider" instead.',
        ], $messages);
        self::assertNotSame((new Scope())->getInner(), $wrappers[0]->getInner());
    }

    /**
     * @template T of object
     *
     * @param callable(): T $factory
     *
     * @return T
     */
    private function silently(callable $factory): object
    {
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);

        try {
            return $factory();
        } finally {
            restore_error_handler();
        }
    }
}
