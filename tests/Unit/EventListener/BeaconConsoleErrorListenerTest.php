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

use function array_key_exists;

final class BeaconConsoleErrorListenerTest extends TestCase
{
    public function testReportsConsoleErrorsWithNestedExtra(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::isInstanceOf(RuntimeException::class),
            self::callback(static function (array $extra): bool {
                return ($extra['console']['command'] ?? null) === 'app:demo'
                    && ($extra['console']['exit_code'] ?? null) === 1
                    && isset($extra['console']['php_sapi'])
                    && !isset($extra['command']);
            }),
        );

        $listener = new BeaconConsoleErrorListener($client, true, []);
        $command  = $this->createMock(Command::class);
        $command->method('getName')->willReturn('app:demo');

        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('boom'), $command);
        $event->setExitCode(1);
        $listener->onConsoleError($event);
    }

    public function testIgnoresConfiguredExceptions(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconConsoleErrorListener($client, true, [RuntimeException::class]);
        $event    = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('skip'));
        $listener->onConsoleError($event);
    }

    public function testSkipsInvalidIgnoreEntriesThenReports(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException');

        /** @var list<mixed> $ignore */
        $ignore   = ['', 123];
        $listener = new BeaconConsoleErrorListener($client, true, $ignore);
        $listener->onConsoleError(new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('report')));
    }

    public function testSkipsWhenDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::never())->method('isEnabled');
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconConsoleErrorListener($client, false, []);
        $listener->onConsoleError(new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('x')));
        self::assertArrayHasKey(ConsoleEvents::ERROR, BeaconConsoleErrorListener::getSubscribedEvents());
    }

    public function testReportsNullCommandNameWhenCommandMissing(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::anything(),
            self::callback(static function (array $extra): bool {
                return array_key_exists('command', $extra['console'])
                    && $extra['console']['command'] === null;
            }),
        );

        $listener = new BeaconConsoleErrorListener($client, true, []);
        $listener->onConsoleError(new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('anon')));
    }
}
