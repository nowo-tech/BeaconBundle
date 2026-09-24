<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\EventListener\BeaconRequestScopeResetListener;
use Nowo\BeaconBundle\EventListener\BeaconTraceRequestListener;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Long-running worker (FrankenPHP) without kernel.reset: the same buffer instances serve
 * consecutive requests of different users.
 */
final class BeaconRequestScopeResetListenerTest extends TestCase
{
    private BreadcrumbBuffer $breadcrumbs;

    private SpanBuffer $spans;

    private Scope $scope;

    private TraceIdProvider $traceIdProvider;

    private PendingTransportRegistry $pendingRegistry;

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->breadcrumbs     = new BreadcrumbBuffer();
        $this->spans           = new SpanBuffer();
        $this->scope           = new Scope();
        $this->traceIdProvider = new TraceIdProvider();
        $this->pendingRegistry = new PendingTransportRegistry();

        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new BeaconTraceRequestListener($this->traceIdProvider));
        $this->dispatcher->addSubscriber(new BeaconRequestScopeResetListener(
            $this->breadcrumbs,
            $this->spans,
            $this->scope,
            $this->traceIdProvider,
            $this->pendingRegistry,
        ));
    }

    public function testSubscribesToKernelRequestBeforeTraceListener(): void
    {
        $events = BeaconRequestScopeResetListener::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertGreaterThan(BeaconTraceRequestListener::getSubscribedEvents()[KernelEvents::REQUEST][1], $events[KernelEvents::REQUEST][1]);
    }

    public function testConsecutiveMainRequestsDoNotShareBreadcrumbsSpansTagsOrTraceId(): void
    {
        // Request 1 (user A)
        $first = $this->mainRequest(Request::create('/orders'));
        $this->breadcrumbs->add('SELECT * FROM orders WHERE user_id = ?', 'db.sql');
        $this->spans->add('db.sql.query', 'SELECT 1', 1.0, 2.0);
        $this->scope->setTag('user_id', 'A');
        $firstTraceId = $first->attributes->get(TraceIdProvider::REQUEST_ATTRIBUTE);

        // Request 2 (user B) on the same worker, no services_resetter in between
        $second = $this->mainRequest(Request::create('/profile'));

        self::assertSame([], $this->breadcrumbs->all());
        self::assertSame([], $this->spans->all());
        self::assertSame([], $this->scope->getTags());
        $secondTraceId = $second->attributes->get(TraceIdProvider::REQUEST_ATTRIBUTE);
        self::assertIsString($firstTraceId);
        self::assertIsString($secondTraceId);
        self::assertNotSame($firstTraceId, $secondTraceId);
        self::assertSame($secondTraceId, $second->headers->get(TraceIdProvider::HEADER));
    }

    public function testInboundTraceHeaderIsStillHonouredAfterReset(): void
    {
        $this->mainRequest(Request::create('/first'));

        $request = Request::create('/second');
        $request->headers->set(TraceIdProvider::HEADER, 'inbound-trace-1234');
        $this->mainRequest($request);

        self::assertSame('inbound-trace-1234', $this->traceIdProvider->get());
    }

    public function testSubRequestKeepsMainRequestData(): void
    {
        $main = $this->mainRequest(Request::create('/page'));
        $this->breadcrumbs->add('main breadcrumb');
        $this->scope->setTag('tenant', 't1');

        $this->dispatcher->dispatch(
            new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/_fragment'), HttpKernelInterface::SUB_REQUEST),
            KernelEvents::REQUEST,
        );

        self::assertCount(1, $this->breadcrumbs->all());
        self::assertSame(['tenant' => 't1'], $this->scope->getTags());
        self::assertSame($main->attributes->get(TraceIdProvider::REQUEST_ATTRIBUTE), $this->traceIdProvider->get());
    }

    private function mainRequest(Request $request): Request
    {
        $this->dispatcher->dispatch(
            new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
            KernelEvents::REQUEST,
        );

        return $request;
    }
}
