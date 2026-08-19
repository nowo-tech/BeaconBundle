<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconTraceRequestListener;
use Nowo\BeaconBundle\Messenger\BeaconTraceMiddleware;
use Nowo\BeaconBundle\Messenger\BeaconTraceStamp;
use Nowo\BeaconBundle\Support\ConsoleInputSnapshot;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

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
}
