<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\AsyncEnvelopeTransport;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\EventListener\FlushPendingTransportsListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class FlushPendingTransportsListenerTest extends TestCase
{
    public function testSubscribedEventsIncludeKernelAndConsoleTerminate(): void
    {
        $events = FlushPendingTransportsListener::getSubscribedEvents();

        self::assertSame(['onKernelTerminate', -1024], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onConsoleTerminate', -1024], $events[ConsoleEvents::TERMINATE]);
    }

    public function testKernelAndConsoleTerminateFlushRegistry(): void
    {
        $dsn   = (new BeaconDsnParser())->parse('https://pubkey:secret@beacon.example.com/5');
        $async = new AsyncEnvelopeTransport(
            new EnvelopeTransport(
                new MockHttpClient(new MockResponse('', ['http_code' => 202])),
                $dsn,
                true,
                5.0,
                null,
                'beacon-bundle/test',
            ),
        );
        $registry = new PendingTransportRegistry();
        $registry->register($async);
        self::assertTrue($async->send('body'));

        $listener = new FlushPendingTransportsListener($registry);
        $kernel   = $this->createMock(HttpKernelInterface::class);

        $listener->onKernelTerminate(new TerminateEvent($kernel, Request::create('/'), new Response()));
        $listener->onConsoleTerminate(new ConsoleTerminateEvent(
            $this->createMock(Command::class),
            new ArrayInput([]),
            new NullOutput(),
            0,
        ));

        self::assertSame($dsn, $async->getDsn());
    }
}
