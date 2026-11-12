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
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_column;
use function array_key_exists;
use function count;
use function gc_collect_cycles;
use function is_array;
use function is_string;
use function memory_get_usage;

use const PHP_INT_MIN;

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

        $command = new Command('app:long-running');
        $input   = new ArrayInput([]);
        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, new NullOutput()), ConsoleEvents::COMMAND);
        $this->fill($breadcrumbs, $spans, $scope, $trace);
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, new NullOutput(), 0), ConsoleEvents::TERMINATE);

        $this->assertEmptyState($breadcrumbs, $spans, $scope, $trace);
    }

    /**
     * Audit NM, through a real console Application in the kept-alive kernel: (a) a nested command whose terminate
     * listener throws, (c) an unknown nested command and (d) a nested command whose console.command is stopped above
     * 4096 never clear the outer command's scope mid-run, and the outermost command still clears it when it ends.
     */
    public function testNestedCommandsWithSkippedConsoleEventsKeepTheOuterBoundary(): void
    {
        $container  = $this->boot();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        [$breadcrumbs, $spans, $scope, $trace] = $this->state($container);

        $throwing = static function (ConsoleTerminateEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:inner') {
                throw new RuntimeException('terminate listener failed');
            }
        };
        $stopping = static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:inner') {
                $event->stopPropagation();
            }
        };

        $cases = [
            'throwing terminate listener' => [ConsoleEvents::TERMINATE, $throwing, 0, 'app:inner'],
            'unknown nested command'      => [null, null, 0, 'app:does-not-exist'],
            'stopped console.command'     => [ConsoleEvents::COMMAND, $stopping, 10000, 'app:inner'],
        ];
        foreach ($cases as $case => [$event, $listener, $priority, $nested]) {
            if ($event !== null && $listener !== null) {
                $dispatcher->addListener($event, $listener, $priority);
            }

            try {
                // Twice: the second run proves the bookkeeping stayed balanced.
                for ($run = 0; $run < 2; ++$run) {
                    $tags = $this->runOuterWithNestedRun($dispatcher, $scope, $nested);
                    self::assertSame('outer', $tags['tenant'] ?? null, $case . ': the nested command kept the outer scope');
                    $this->assertEmptyState($breadcrumbs, $spans, $scope, $trace);
                }
            } finally {
                if ($listener !== null) {
                    $dispatcher->removeListener($event, $listener);
                }
            }
        }
    }

    /**
     * Audits NL2-v / NL2-v2 probes through a real console Application: a self-recursive command whose error listener
     * throws, an error listener at PHP_INT_MIN that throws, a throwing WorkerMessageReceivedEvent listener inside a
     * command, and a failing command with a throwing error listener inside a message followed by the message failure
     * and the worker stop. After each, the next outermost command still clears the scope when it ends.
     */
    public function testFailedRunsAreHealedAndNeverKeepTheProcessInsideAUnit(): void
    {
        $container  = $this->boot();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        [$breadcrumbs, $spans, $scope, $trace] = $this->state($container);
        $throwing                              = static function (): never {
            throw new RuntimeException('listener failed');
        };
        $application = new Application('worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);
        $depth     = 0;
        $recursive = (new Command('app:rec'))->setCode(static function (InputInterface $input, OutputInterface $output) use ($application, $dispatcher, $throwing, &$depth): int {
            if (++$depth > 1) {
                throw new RuntimeException('inner run failed');
            }
            $dispatcher->addListener(ConsoleEvents::ERROR, $throwing);
            try {
                $application->run(new ArrayInput(['command' => 'app:rec']), $output);
            } catch (Throwable) {
            } finally {
                $dispatcher->removeListener(ConsoleEvents::ERROR, $throwing);
            }

            return Command::SUCCESS;
        });
        $failing = (new Command('app:fail'))->setCode(static function (): never {
            throw new RuntimeException('command failed');
        });
        $consume = (new Command('app:consume'))->setCode(static function () use ($dispatcher, $throwing): int {
            $dispatcher->addListener(WorkerMessageReceivedEvent::class, $throwing);
            try {
                $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
            } catch (RuntimeException) {
            } finally {
                $dispatcher->removeListener(WorkerMessageReceivedEvent::class, $throwing);
            }

            return Command::SUCCESS;
        });
        $application->setCommandLoader(new FactoryCommandLoader([
            'app:rec'     => static fn (): Command => $recursive,
            'app:fail'    => static fn (): Command => $failing,
            'app:consume' => static fn (): Command => $consume,
        ]));

        $probes = [
            'self-recursive command, throwing error listener' => static function () use ($application): void {
                $application->run(new ArrayInput(['command' => 'app:rec']), new NullOutput());
            },
            'error listener at PHP_INT_MIN throwing' => static function () use ($application, $dispatcher, $throwing): void {
                $dispatcher->addListener(ConsoleEvents::ERROR, $throwing, PHP_INT_MIN);
                try {
                    $application->run(new ArrayInput(['command' => 'app:fail']), new NullOutput());
                } catch (RuntimeException) {
                } finally {
                    $dispatcher->removeListener(ConsoleEvents::ERROR, $throwing);
                }
            },
            'throwing received listener inside a command' => static function () use ($application): void {
                $application->run(new ArrayInput(['command' => 'app:consume']), new NullOutput());
            },
            'failing command inside a message, worker stopped' => function () use ($application, $dispatcher, $throwing): void {
                $envelope = new Envelope(new stdClass());
                $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
                $dispatcher->addListener(ConsoleEvents::ERROR, $throwing);
                try {
                    $application->run(new ArrayInput(['command' => 'app:fail']), new NullOutput());
                } catch (RuntimeException) {
                } finally {
                    $dispatcher->removeListener(ConsoleEvents::ERROR, $throwing);
                }
                $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('handler failed')));
                $dispatcher->dispatch(new WorkerStoppedEvent($this->createStub(Worker::class)));
            },
        ];

        foreach ($probes as $probe => $run) {
            $depth = 0;
            $run();
            $tags = $this->runOuterWithNestedRun($dispatcher, $scope, 'app:inner');
            self::assertSame('outer', $tags['tenant'] ?? null, $probe);
            $this->assertEmptyState($breadcrumbs, $spans, $scope, $trace);
        }
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

    /**
     * Runs app:outer: it tags the scope, runs $nested in-process (a failure of the nested run is swallowed) and
     * returns the scope tags seen right after the nested run.
     *
     * @return array<string, string>
     */
    private function runOuterWithNestedRun(EventDispatcherInterface $dispatcher, Scope $scope, string $nested): array
    {
        $application = new Application('worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);

        $tags  = [];
        $inner = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $outer = (new Command('app:outer'))->setCode(static function (InputInterface $input, OutputInterface $output) use ($application, $scope, $nested, &$tags): int {
            $scope->setTag('tenant', 'outer');
            try {
                $application->doRun(new ArrayInput(['command' => $nested]), $output);
            } catch (Throwable) {
                // The nested run failed (throwing listener, unknown command); the outer command goes on.
            }
            $tags = $scope->getTags();

            return Command::SUCCESS;
        });
        $application->setCommandLoader(new FactoryCommandLoader([
            'app:outer' => static fn (): Command => $outer,
            'app:inner' => static fn (): Command => $inner,
        ]));

        self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:outer']), new NullOutput()));

        return $tags;
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
