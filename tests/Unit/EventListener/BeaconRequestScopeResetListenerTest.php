<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconRequestScopeResetListener;
use Nowo\BeaconBundle\EventListener\BeaconTraceRequestListener;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;

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
            [$this->breadcrumbs, $this->spans, $this->scope, $this->traceIdProvider],
            [$this->pendingRegistry],
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

    public function testSubscribesToMessengerAndConsoleBoundariesAfterTheFailureCapture(): void
    {
        $events = BeaconRequestScopeResetListener::getSubscribedEvents();

        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', 4096], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleTerminate', -4096], $events[ConsoleEvents::TERMINATE]);
        // The failed-message capture, Scheduler check-in and message transaction run first and keep the scope.
        self::assertLessThan(BeaconMessengerFailedListener::PRIORITY, $events[WorkerMessageFailedEvent::class][1]);
        self::assertLessThan(-1024, $events[WorkerMessageFailedEvent::class][1]);
        self::assertLessThan(-2048, $events[WorkerMessageHandledEvent::class][1], 'after the pending flush');
    }

    public function testMessageBoundariesClearTheScope(): void
    {
        $envelope = new Envelope(new stdClass());

        $this->fill('A');
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->assertEmptyScope();

        $this->fill('A');
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'async'));
        $this->assertEmptyScope();

        $this->fill('B');
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));
        $this->assertEmptyScope();
    }

    public function testConsoleTerminateClearsTheScope(): void
    {
        $this->fill('A');
        $this->dispatcher->dispatch(
            new ConsoleTerminateEvent(new Command('app:long'), new ArrayInput([]), new NullOutput(), 0),
            ConsoleEvents::TERMINATE,
        );

        $this->assertEmptyScope();
    }

    public function testNestedCommandDoesNotClearTheOuterCommandScope(): void
    {
        $this->consoleCommand();
        $this->fill('outer');
        $this->consoleCommand();
        $this->consoleTerminate();

        self::assertCount(1, $this->breadcrumbs->all(), 'a nested in-process command keeps the outer scope');
        self::assertSame(['user_id' => 'outer'], $this->scope->getTags());
        self::assertSame('trace-outer', $this->traceIdProvider->get());

        $this->consoleTerminate();
        $this->assertEmptyScope();
    }

    public function testCommandRunFromAMessageHandlerDoesNotClearTheMessageScope(): void
    {
        $envelope = new Envelope(new stdClass());
        $this->consoleCommand(); // messenger:consume
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->fill('message');
        $this->consoleCommand(); // Application::doRun() inside the handler
        $this->consoleTerminate();

        self::assertCount(1, $this->breadcrumbs->all(), 'the message keeps its scope until it is handled');
        self::assertSame('trace-message', $this->traceIdProvider->get());

        $this->dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));
        $this->assertEmptyScope();

        // A command run outside any message, with no outer command, clears at its own terminate.
        $this->consoleTerminate(); // messenger:consume ends
        $this->fill('after');
        $this->consoleCommand();
        $this->consoleTerminate();
        $this->assertEmptyScope();
    }

    public function testSkippedMessageDoesNotBlockTheClearAfterTheWorkerStops(): void
    {
        $worker = $this->createStub(Worker::class);
        $this->consoleCommand();
        // Received without a handled / failed event (a listener chose not to handle it).
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerStoppedEvent($worker));
        $this->fill('A');
        $this->consoleTerminate();

        $this->assertEmptyScope();
    }

    public function testTerminateWithoutCommandEventStillClears(): void
    {
        $this->fill('A');
        $this->consoleTerminate();
        $this->consoleTerminate();

        $this->assertEmptyScope();
    }

    public function testDirectClearResetsEveryService(): void
    {
        $this->fill('A');
        (new BeaconRequestScopeResetListener([$this->breadcrumbs, $this->spans, $this->scope, $this->traceIdProvider]))->clear();

        $this->assertEmptyScope();
    }

    public function testBoundaryNeverBuildsAnUninitializedService(): void
    {
        $container = new ContainerBuilder();
        $container->register(Scope::class)->setPublic(true);
        $container->register(PendingTransportRegistry::class)->setPublic(true);
        $container->register(BeaconRequestScopeResetListener::class)
            ->setPublic(true)
            ->setArguments([
                new IteratorArgument([new Reference(Scope::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]),
                new IteratorArgument([new Reference(PendingTransportRegistry::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]),
            ]);
        $container->compile(true);

        $listener = $container->get(BeaconRequestScopeResetListener::class);
        self::assertInstanceOf(BeaconRequestScopeResetListener::class, $listener);
        $listener->onMessageReceived();
        self::assertFalse($container->initialized(Scope::class), 'the boundary does not build the scope');
        self::assertFalse($container->initialized(PendingTransportRegistry::class));

        $scope = $container->get(Scope::class);
        self::assertInstanceOf(Scope::class, $scope);
        $scope->setTag('tenant', 'A');
        $listener->clear();
        self::assertSame([], $scope->getTags(), 'the same listener instance clears a service built later');
    }

    private function consoleCommand(): void
    {
        $this->dispatcher->dispatch(new ConsoleCommandEvent(new Command('app:probe'), new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);
    }

    private function consoleTerminate(): void
    {
        $this->dispatcher->dispatch(
            new ConsoleTerminateEvent(new Command('app:probe'), new ArrayInput([]), new NullOutput(), 0),
            ConsoleEvents::TERMINATE,
        );
    }

    private function fill(string $user): void
    {
        $this->breadcrumbs->add('breadcrumb of ' . $user, 'probe');
        $this->spans->add('db.sql.query', 'SELECT 1', 1.0, 2.0);
        $this->scope->setTag('user_id', $user);
        $this->traceIdProvider->set('trace-' . $user);
    }

    private function assertEmptyScope(): void
    {
        self::assertSame([], $this->breadcrumbs->all());
        self::assertSame([], $this->spans->all());
        self::assertSame([], $this->scope->getTags());
        self::assertNull($this->traceIdProvider->get());
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
