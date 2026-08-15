<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconConsoleErrorListener;
use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
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
                    && array_key_exists('interactive', $extra['console'])
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

    public function testIncludesRedactedArgumentsAndOptions(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::anything(),
            self::callback(static function (array $extra): bool {
                $console = $extra['console'] ?? [];

                return ($console['command'] ?? null) === 'secrets:set'
                    && ($console['arguments']['name'] ?? null) === 'APP_SECRET'
                    && ($console['options']['password'] ?? null) === SensitiveValueRedactor::FILTERED
                    && ($console['options']['env'] ?? null) === 'dev';
            }),
        );

        $definition = new InputDefinition([
            new InputArgument('name', InputArgument::REQUIRED),
            new InputOption('password', null, InputOption::VALUE_REQUIRED),
            new InputOption('env', null, InputOption::VALUE_REQUIRED, '', 'dev'),
        ]);
        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('secrets:set');
        $command->method('getDefinition')->willReturn($definition);

        $input = new ArrayInput([
            'name'       => 'APP_SECRET',
            '--password' => 's3cret',
            '--env'      => 'dev',
        ], $definition);

        $listener = new BeaconConsoleErrorListener($client, true, []);
        $listener->onConsoleError(new ConsoleErrorEvent($input, new NullOutput(), new RuntimeException('boom'), $command));
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
