<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit;

use DateTimeImmutable;
use ErrorException;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconTraceRequestListener;
use Nowo\BeaconBundle\Messenger\BeaconTraceMiddleware;
use Nowo\BeaconBundle\Messenger\BeaconTraceStamp;
use Nowo\BeaconBundle\Support\ConsoleInputSnapshot;
use Nowo\BeaconBundle\Support\HttpRequestSnapshot;
use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

use const E_USER_ERROR;
use const E_USER_NOTICE;

final class CoverageCompletionTest extends TestCase
{
    public function testFatalErrorHandlerRegisterAndShutdownPaths(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $disabled = new BeaconFatalErrorHandler($client, false);
        $disabled->register();
        $disabled->onShutdown();

        $handler = new BeaconFatalErrorHandler($client, true);
        $handler->register();
        $handler->register();
        $handler->onShutdown();

        $clientOff = $this->createMock(BeaconClientInterface::class);
        $clientOff->method('isEnabled')->willReturn(false);
        $clientOff->expects(self::never())->method('captureException');
        (new BeaconFatalErrorHandler($clientOff, true))->onShutdown();
    }

    public function testFatalErrorHandlerCapturesUserError(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::isInstanceOf(ErrorException::class),
            self::callback(static fn (array $extra): bool => isset($extra['fatal']['type'], $extra['fatal']['file'], $extra['fatal']['line'])),
        );

        $handler = new BeaconFatalErrorHandler($client, true);
        $handler->captureFatalError([
            'type'    => E_USER_ERROR,
            'message' => 'coverage fatal',
            'file'    => __FILE__,
            'line'    => __LINE__,
        ]);
    }

    public function testFatalErrorHandlerIgnoresNonFatalErrorTypes(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $handler = new BeaconFatalErrorHandler($client, true);
        $handler->captureFatalError([
            'type'    => E_USER_NOTICE,
            'message' => 'notice only',
            'file'    => __FILE__,
            'line'    => __LINE__,
        ]);
        $handler->captureFatalError(null);
    }

    public function testTraceRequestListenerSeedsAndPropagatesHeader(): void
    {
        $provider = new TraceIdProvider();
        $kernel   = $this->createMock(HttpKernelInterface::class);

        $disabled = new BeaconTraceRequestListener($provider, false);
        $disabled->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));

        $request = Request::create('/');
        $request->headers->set(TraceIdProvider::HEADER, 'abcd1234-abcd1234');
        $listener = new BeaconTraceRequestListener(new TraceIdProvider(), true);
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('abcd1234-abcd1234', $request->attributes->get(TraceIdProvider::REQUEST_ATTRIBUTE));
        self::assertSame('abcd1234-abcd1234', $request->headers->get(TraceIdProvider::HEADER));

        $sub = Request::create('/sub');
        $listener->onKernelRequest(new RequestEvent($kernel, $sub, HttpKernelInterface::SUB_REQUEST));
        self::assertNull($sub->attributes->get(TraceIdProvider::REQUEST_ATTRIBUTE));
    }

    public function testTraceMiddlewareRestoresOrCreatesStamp(): void
    {
        $provider = new TraceIdProvider();
        $next     = $this->createMock(MiddlewareInterface::class);
        $next->method('handle')->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($next);

        $middleware = new BeaconTraceMiddleware($provider);
        $result     = $middleware->handle(new Envelope(new stdClass()), $stack);
        self::assertInstanceOf(BeaconTraceStamp::class, $result->last(BeaconTraceStamp::class));

        $provider->reset();
        $provider->set('trace-from-stamp-12345678');
        $envelope = new Envelope(new stdClass(), [new BeaconTraceStamp('trace-from-stamp-12345678')]);
        $middleware->handle($envelope, $stack);
        self::assertSame('trace-from-stamp-12345678', $provider->get());
    }

    public function testMessengerListenerAddsHandlerTransportAndFirstFailureMetadata(): void
    {
        $provider = new TraceIdProvider();
        $client   = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::isInstanceOf(HandlerFailedException::class),
            self::callback(static function (array $extra): bool {
                $messenger = $extra['messenger'] ?? [];

                return ($messenger['transport'] ?? null) === 'async'
                    && ($messenger['handler_class'] ?? null) === 'App\\Handler\\DemoHandler::__invoke'
                    && isset($messenger['first_failure_at']);
            }),
        );

        $failedAt = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        $envelope = new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new RedeliveryStamp(1, $failedAt),
        ]);
        $throwable = new HandlerFailedException($envelope, [
            'App\\Handler\\DemoHandler::__invoke' => new RuntimeException('handler failed'),
        ]);

        $event = new WorkerMessageFailedEvent($envelope, 'async', $throwable);
        self::assertFalse($event->willRetry());

        (new BeaconMessengerFailedListener($client, true, [], true, $provider))($event);
    }

    public function testMessengerListenerRestoresTraceAndAddsMetadata(): void
    {
        $provider = new TraceIdProvider();
        $client   = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::isInstanceOf(RuntimeException::class),
            self::callback(static function (array $extra): bool {
                return ($extra['messenger']['bus'] ?? null) === 'command.bus'
                    && ($extra['messenger']['transport_message_id'] ?? null) === 'msg-1'
                    && isset($extra['messenger']['first_failure_at']);
            }),
        );

        $envelope = new Envelope(new stdClass(), [
            new BeaconTraceStamp('trace-meta-123456789'),
            new BusNameStamp('command.bus'),
            new TransportMessageIdStamp('msg-1'),
            new RedeliveryStamp(0),
        ]);

        $listener = new BeaconMessengerFailedListener($client, true, [], true, $provider);
        $listener(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('fail')));

        self::assertSame('trace-meta-123456789', $provider->get());
    }

    public function testConsoleInputSnapshotCapturesArgumentsOptionsAndRuntime(): void
    {
        $command = new class extends Command {
            protected function configure(): void
            {
                $this->setName('demo');
                $this->addArgument('name');
                $this->addOption('verbose', null, InputOption::VALUE_NONE);
            }
        };
        $command->setCode(static fn (): int => 0);

        $definition = $command->getDefinition();
        $input      = new ArrayInput(['name' => 'acme', '--verbose' => true], $definition);
        $input->bind($definition);
        $output = new BufferedOutput();

        $snapshot = ConsoleInputSnapshot::from($input, $command);
        self::assertTrue($snapshot['interactive']);
        self::assertSame('acme', $snapshot['arguments']['name'] ?? null);
        self::assertTrue($snapshot['options']['verbose'] ?? false);

        $runtime = ConsoleInputSnapshot::runtime($command, $output);
        self::assertSame($command::class, $runtime['command_class']);
        self::assertArrayHasKey('cwd', $runtime);
    }

    public function testConsoleInputSnapshotRuntimeWithoutCommand(): void
    {
        $runtime = ConsoleInputSnapshot::runtime(null, null);
        self::assertArrayNotHasKey('command_class', $runtime);
        self::assertArrayNotHasKey('verbosity', $runtime);
    }

    public function testTraceIdProviderFromMixed(): void
    {
        self::assertNull(TraceIdProvider::fromMixed(123));
        self::assertSame('valid-trace-12345678', TraceIdProvider::fromMixed(' valid-trace-12345678 '));
    }

    public function testHttpRequestSnapshotHandlesArrayControllersAndEdgeCases(): void
    {
        $objectController = Request::create('/');
        $objectController->attributes->set('_controller', [new class {
            public function show(): void
            {
            }
        }, 'show']);
        self::assertStringContainsString('::show', HttpRequestSnapshot::fromRequest($objectController)['controller'] ?? '');

        $callableController = Request::create('/');
        $callableController->attributes->set('_controller', ['App\\Controller\\DemoController', 'index']);
        self::assertSame('App\\Controller\\DemoController::index', HttpRequestSnapshot::fromRequest($callableController)['controller'] ?? null);

        $classOnly = Request::create('/');
        $classOnly->attributes->set('_controller', ['App\\Controller\\DemoController']);
        self::assertSame('App\\Controller\\DemoController', HttpRequestSnapshot::fromRequest($classOnly)['controller'] ?? null);

        $fallback = Request::create('/');
        $fallback->attributes->set('_controller', [123, null]);
        self::assertSame('array', HttpRequestSnapshot::fromRequest($fallback)['controller'] ?? null);
    }

    public function testConsoleInputSnapshotHandlesUnboundInputAndMissingArguments(): void
    {
        $command = new Command('needs-arg');
        $command->addArgument('required', InputArgument::REQUIRED);

        $broken = $this->createMock(InputInterface::class);
        $broken->method('isInteractive')->willReturn(false);
        $broken->method('getArguments')->willThrowException(new RuntimeException('unbound'));
        $broken->method('getOptions')->willThrowException(new RuntimeException('unbound'));
        $broken->method('hasArgument')->willReturnMap([['required', false]]);
        $broken->method('getArgument')->willReturn('');

        $snapshot = ConsoleInputSnapshot::from($broken, $command);
        self::assertFalse($snapshot['interactive']);
        self::assertSame(['required'], $snapshot['missing_arguments'] ?? null);

        $throwsOnGet = $this->createMock(InputInterface::class);
        $throwsOnGet->method('isInteractive')->willReturn(false);
        $throwsOnGet->method('getArguments')->willThrowException(new RuntimeException('unbound'));
        $throwsOnGet->method('getOptions')->willThrowException(new RuntimeException('unbound'));
        $throwsOnGet->method('hasArgument')->willReturn(true);
        $throwsOnGet->method('getArgument')->willThrowException(new RuntimeException('missing'));

        self::assertSame(['required'], ConsoleInputSnapshot::from($throwsOnGet, $command)['missing_arguments'] ?? null);

        $optionCommand = new Command('opts');
        $optionCommand->addOption('flag', null, InputOption::VALUE_NONE);
        $optionInput = new ArrayInput(['--flag' => true], $optionCommand->getDefinition());
        self::assertTrue(ConsoleInputSnapshot::from($optionInput, $optionCommand)['options']['flag'] ?? false);
        self::assertSame(['interactive' => false], ConsoleInputSnapshot::from($broken, null));
    }

    public function testSensitiveValueRedactorCoversStringablePrivateKeyAndUnknownTypes(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'mysql://user:secret@db/app';
            }
        };

        self::assertSame('', SensitiveValueRedactor::redactValue(''));
        self::assertSame(
            'mysql://' . SensitiveValueRedactor::FILTERED . '@db/app',
            SensitiveValueRedactor::redactValue($stringable),
        );
        self::assertSame(
            SensitiveValueRedactor::FILTERED,
            SensitiveValueRedactor::redactValue("-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----"),
        );
        self::assertSame(SensitiveValueRedactor::FILTERED, SensitiveValueRedactor::redactValue(fopen('php://memory', 'r')));
    }
}
