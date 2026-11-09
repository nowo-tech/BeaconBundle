<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Monolog;

use DateTimeImmutable;
use DomainException;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Nowo\BeaconBundle\Monolog\BeaconMonologHandler;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * BB-1: the Monolog handler applies the same ignore rule as the exception listener.
 */
final class BeaconMonologHandlerIgnoreTest extends TestCase
{
    public function testNotFoundLoggedAtErrorIsNotSent(): void
    {
        $client = $this->client();
        $this->handle(new BeaconMonologHandler($client), new NotFoundHttpException('No route found for "GET /nope"'));

        self::assertSame([], $client->exceptions);
        self::assertSame([], $client->messages);
    }

    public function testAccessDeniedIsNotSent(): void
    {
        $client = $this->client();
        $this->handle(new BeaconMonologHandler($client), new AccessDeniedHttpException('Access Denied.'));

        self::assertSame([], $client->exceptions);
        self::assertSame([], $client->messages);
    }

    public function testOtherClientErrorsAreNotSent(): void
    {
        $client  = $this->client();
        $handler = new BeaconMonologHandler($client);
        $this->handle($handler, new MethodNotAllowedHttpException(['GET']));
        $this->handle($handler, new HttpException(499, 'client closed'));

        self::assertSame([], $client->exceptions);
    }

    public function testServerErrorHttpExceptionIsSent(): void
    {
        $client  = $this->client();
        $handler = new BeaconMonologHandler($client);
        $five00  = new HttpException(500, 'boom');
        $five03  = new ServiceUnavailableHttpException(null, 'down');
        $this->handle($handler, $five00);
        $this->handle($handler, $five03);

        self::assertCount(2, $client->exceptions);
        self::assertSame($five00, $client->exceptions[0]['throwable']);
        self::assertSame($five03, $client->exceptions[1]['throwable']);
    }

    public function testClientErrorsAreSentWhenOptedOut(): void
    {
        $client = $this->client();
        $this->handle(
            new BeaconMonologHandler($client, Level::Error, true, [], false),
            new NotFoundHttpException('nope'),
        );

        self::assertCount(1, $client->exceptions);
    }

    public function testConfiguredIgnoredClassIsNotSent(): void
    {
        $client  = $this->client();
        $handler = new BeaconMonologHandler($client, Level::Error, true, [LogicException::class]);
        $this->handle($handler, new LogicException('ignored'));
        $this->handle($handler, new DomainException('subclass of LogicException, ignored too'));
        $this->handle($handler, new RuntimeException('sent'));

        self::assertCount(1, $client->exceptions);
        self::assertInstanceOf(RuntimeException::class, $client->exceptions[0]['throwable']);
    }

    public function testRecordsWithoutExceptionAreUnchanged(): void
    {
        $client  = $this->client();
        $handler = new BeaconMonologHandler($client, Level::Error, true, [LogicException::class]);
        $handler->handle($this->record('plain error', ['exception' => 'not a throwable', 'code' => 404]));

        self::assertSame([], $client->exceptions);
        self::assertCount(1, $client->messages);
        self::assertSame('plain error', $client->messages[0]['message']);
        self::assertSame('error', $client->messages[0]['level']);
    }

    public function testIgnoredRecordStillReachesOtherHandlers(): void
    {
        $client = $this->client();
        $other  = new TestHandler();
        $logger = new Logger('request', [new BeaconMonologHandler($client), $other]);

        $logger->error('Uncaught PHP Exception NotFoundHttpException', ['exception' => new NotFoundHttpException('nope')]);

        self::assertSame([], $client->exceptions);
        self::assertTrue($other->hasErrorThatContains('NotFoundHttpException'));
    }

    private function client(): RecordingBeaconClient
    {
        return new RecordingBeaconClient();
    }

    private function handle(BeaconMonologHandler $handler, Throwable $exception): void
    {
        self::assertFalse($handler->handle($this->record('Uncaught PHP Exception', ['exception' => $exception])), 'record must bubble');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(string $message, array $context): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'request',
            level: Level::Error,
            message: $message,
            context: $context,
            extra: [],
        );
    }
}
