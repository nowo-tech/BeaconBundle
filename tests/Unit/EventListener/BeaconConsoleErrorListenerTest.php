<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class BeaconConsoleErrorListenerTest extends TestCase
{
    public function testReportsConsoleErrors(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException');

        $listener = new BeaconConsoleErrorListener($client, true, []);
        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('app:demo');

        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('boom'), $command);
        $listener->onConsoleError($event);
    }

    public function testIgnoresConfiguredExceptions(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconConsoleErrorListener($client, true, [RuntimeException::class]);
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('skip'), null);
        $listener->onConsoleError($event);
    }

    public function testSkipsWhenDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::never())->method('isEnabled');
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconConsoleErrorListener($client, false, []);
        $listener->onConsoleError(new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('x'), null));
        self::assertArrayHasKey(ConsoleEvents::ERROR, BeaconConsoleErrorListener::getSubscribedEvents());
    }
}
