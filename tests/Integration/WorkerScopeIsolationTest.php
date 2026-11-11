<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\EventListener\BeaconRequestScopeResetListener;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Tests\Fixtures\WorkerScope\RecordingBeforeSend;
use Nowo\BeaconBundle\Tests\Fixtures\WorkerScope\ScopeProbeMessage;
use Nowo\BeaconBundle\Tests\Fixtures\WorkerScope\WorkerScopeKernel;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

use function array_column;
use function array_key_exists;
use function count;
use function gc_collect_cycles;
use function is_array;
use function is_string;
use function memory_get_usage;

/**
 * REQ-RUNTIME-002: ONE kept-alive kernel, the services resetter never called (`http_kernel` driven directly, a
 * Messenger worker run without `ResetServicesListener`, i.e. `messenger:consume --no-reset`). Two users alternate over
 * 1,000 iterations of: an HTTP request, a message that succeeds and a message that fails. Every captured event holds
 * only the breadcrumbs, tags and trace id of its own unit of work; the failure event of a message still carries that
 * message's scope (the clear runs after the bundle's failure capture); memory stays flat.
 */
#[CoversClass(BeaconRequestScopeResetListener::class)]
final class WorkerScopeIsolationTest extends TestCase
{
    private const ITERATIONS = 1000;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private ?WorkerScopeKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(WorkerScopeKernel::baseDir());
    }

    public function testHttpAndMessengerUnitsOfWorkNeverShareScopeWithoutTheResetter(): void
    {
        $container  = $this->boot();
        $http       = $container->get('http_kernel');
        $bus        = $container->get('messenger.default_bus');
        $dispatcher = $container->get('event_dispatcher');
        $recorder   = $container->get(RecordingBeforeSend::class);
        self::assertInstanceOf(HttpKernel::class, $http);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertInstanceOf(RecordingBeforeSend::class, $recorder);

        // One message per run: stop as soon as the worker reports it handled (or found nothing).
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            $event->getWorker()->stop();
        });
        $transport = new InMemoryTransport();

        /** @var array<string, int> $traceIds */
        $traceIds = [];
        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $user  = $i % 2 === 0 ? 'user-a' : 'user-b';
            $other = $i % 2 === 0 ? 'user-b' : 'user-a';

            // HTTP request of $user; it captures an event on odd iterations, otherwise it leaves its state behind.
            $capture  = $i % 2 === 1;
            $request  = Request::create('/probe/' . $user . '/' . $i . '/' . ($capture ? '1' : '0'));
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $http->terminate($request, $response);

            // A message of the other user that succeeds (leaves breadcrumbs and tags), then a failing one of $user.
            $this->consume($transport, $bus, $dispatcher, new ScopeProbeMessage($other, $i, false));
            $this->consume($transport, $bus, $dispatcher, new ScopeProbeMessage($user, $i, true));

            $events = $recorder->payloads;
            self::assertCount($capture ? 2 : 1, $events, 'iteration ' . $i);
            if ($capture) {
                $this->assertOwnScope($events[0], 'http-' . $user . '-' . $i, $user, 'http_only', $i, $traceIds);
            }
            $failure = $events[count($events) - 1];
            self::assertArrayHasKey('messenger', $this->extra($failure), 'the failure event comes from the bundle listener');
            $this->assertOwnScope($failure, 'msg-' . $user . '-' . $i, $user, 'msg_only', $i, $traceIds);

            $recorder->payloads = [];
            $transport->reset();
            if ($i === 50) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertCount(self::ITERATIONS + self::ITERATIONS / 2, $traceIds, 'every captured unit of work has its own trace id');
        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
    }

    public function testConsoleTerminateClearsTheScope(): void
    {
        $container  = $this->boot();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        [$breadcrumbs, $spans, $scope, $trace] = $this->state($container);

        $this->fill($breadcrumbs, $spans, $scope, $trace);
        $dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:long-running'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);

        $this->assertEmptyState($breadcrumbs, $spans, $scope, $trace);
    }

    public function testServicesResetterStillResetsEveryScopeService(): void
    {
        $container                             = $this->boot();
        [$breadcrumbs, $spans, $scope, $trace] = $this->state($container);
        $resetter                              = $container->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);

        $this->fill($breadcrumbs, $spans, $scope, $trace);
        $resetter->reset();

        $this->assertEmptyState($breadcrumbs, $spans, $scope, $trace);
    }

    private function consume(InMemoryTransport $transport, MessageBusInterface $bus, EventDispatcherInterface $dispatcher, ScopeProbeMessage $message): void
    {
        $transport->send(new Envelope($message));
        (new Worker(['probe' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int> $traceIds
     */
    private function assertOwnScope(array $payload, string $breadcrumb, string $user, string $ownTag, int $iteration, array &$traceIds): void
    {
        $context = $breadcrumb . ' (iteration ' . $iteration . ')';
        $values  = is_array($payload['breadcrumbs'] ?? null) && is_array($payload['breadcrumbs']['values'] ?? null) ? $payload['breadcrumbs']['values'] : [];
        self::assertSame([$breadcrumb], array_column($values, 'message'), $context . ': only its own breadcrumbs');

        $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];
        self::assertSame($user, $tags['tenant'] ?? null, $context . ': its own tenant tag');
        self::assertSame((string) $iteration, $tags[$ownTag] ?? null, $context);
        $foreignTag = $ownTag === 'http_only' ? 'msg_only' : 'http_only';
        self::assertArrayNotHasKey($foreignTag, $tags, $context . ': no tag of another unit of work');

        $traceId = $this->extra($payload)['trace_id'] ?? null;
        self::assertTrue(is_string($traceId) && $traceId !== '', $context . ': trace id');
        self::assertFalse(array_key_exists($traceId, $traceIds), $context . ': trace id reused from another unit of work');
        $traceIds[$traceId] = $iteration;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function extra(array $payload): array
    {
        return is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
    }

    /**
     * @return array{BreadcrumbBuffer, SpanBuffer, Scope, TraceIdProvider}
     */
    private function state(ContainerInterface $container): array
    {
        $breadcrumbs = $container->get(BreadcrumbBuffer::class);
        $spans       = $container->get(SpanBuffer::class);
        $scope       = $container->get(Scope::class);
        $trace       = $container->get(TraceIdProvider::class);
        self::assertInstanceOf(BreadcrumbBuffer::class, $breadcrumbs);
        self::assertInstanceOf(SpanBuffer::class, $spans);
        self::assertInstanceOf(Scope::class, $scope);
        self::assertInstanceOf(TraceIdProvider::class, $trace);

        return [$breadcrumbs, $spans, $scope, $trace];
    }

    private function fill(BreadcrumbBuffer $breadcrumbs, SpanBuffer $spans, Scope $scope, TraceIdProvider $trace): void
    {
        $breadcrumbs->add('previous unit of work', 'probe');
        $spans->add('db.sql.query', 'SELECT 1', 1.0, 2.0);
        $scope->setTag('tenant', 'user-a');
        $trace->set('previous-trace-id');
    }

    private function assertEmptyState(BreadcrumbBuffer $breadcrumbs, SpanBuffer $spans, Scope $scope, TraceIdProvider $trace): void
    {
        self::assertSame([], $breadcrumbs->all());
        self::assertSame([], $spans->all());
        self::assertSame([], $scope->getTags());
        self::assertNull($trace->get());
    }

    /**
     * Memory after a cycle collection: request / envelope objects form reference cycles that PHP frees only when the
     * collector runs, so the raw figure depends on GC timing, not on retained state.
     */
    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }

    private function boot(): ContainerInterface
    {
        $this->kernel = new WorkerScopeKernel();
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
