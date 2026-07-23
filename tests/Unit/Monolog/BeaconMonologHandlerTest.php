<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Monolog;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BeaconMonologHandlerTest extends TestCase
{
    public function testForwardsMessage(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureMessage')->with(
            'boom log',
            'error',
            self::callback(static fn (array $extra): bool => ($extra['monolog'] ?? false) === true),
        );

        $handler = new BeaconMonologHandler($client, Level::Error);
        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'boom log',
            context: [],
            extra: [],
        ));
    }

    public function testForwardsExceptionContext(): void
    {
        $exception = new RuntimeException('x');
        $client    = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with($exception, self::isType('array'));

        $handler = new BeaconMonologHandler($client, Level::Error);
        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'with exception',
            context: ['exception' => $exception],
            extra: [],
        ));
    }

    public function testSkipsWhenClientDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(false);
        $client->expects(self::never())->method('captureMessage');
        $client->expects(self::never())->method('captureException');

        $handler = new BeaconMonologHandler($client, Level::Error);
        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'ignored',
            context: [],
            extra: [],
        ));
    }
}
