<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

use function gc_collect_cycles;
use function restore_error_handler;
use function set_error_handler;
use function trigger_error;

use const E_ALL;
use const E_COMPILE_WARNING;
use const E_CORE_WARNING;
use const E_DEPRECATED;
use const E_NOTICE;
use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;

final class BeaconPhpWarningHandlerTest extends TestCase
{
    private ?BeaconPhpWarningHandler $registeredHandler = null;

    protected function tearDown(): void
    {
        if ($this->registeredHandler instanceof BeaconPhpWarningHandler) {
            $this->registeredHandler->unregister();
            $this->registeredHandler = null;
        }

        parent::tearDown();
    }

    public function testLevelsFromNames(): void
    {
        $bits = BeaconPhpWarningHandler::levelsFromNames(['warning', 'user_notice', 'unknown']);
        self::assertSame(E_WARNING | E_USER_NOTICE, $bits);
        self::assertSame(0, BeaconPhpWarningHandler::levelsFromNames([]));
    }

    public function testCaptureSendsWarningMessageWithFingerprint(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureMessage')->with(
            'User Warning: missing key',
            'warning',
            self::callback(static function (array $extra): bool {
                return ($extra['php_error']['type'] ?? null) === E_USER_WARNING
                    && ($extra['php_error']['message'] ?? null) === 'missing key';
            }),
            self::callback(static function (array $fingerprint): bool {
                return $fingerprint[0] === 'php-warning' && $fingerprint[1] === 'User Warning';
            }),
        );

        $handler = new BeaconPhpWarningHandler($client, true);
        $handler->capture(E_USER_WARNING, 'missing key', __FILE__, 42);
    }

    public function testHandleContinuesWithoutCallingPrevious(): void
    {
        $calledPrevious = false;
        $previous       = static function () use (&$calledPrevious): bool {
            $calledPrevious = true;

            return false;
        };

        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureMessage');

        $handler = new BeaconPhpWarningHandler($client, true, BeaconPhpWarningHandler::DEFAULT_LEVELS, true);
        (new ReflectionProperty(BeaconPhpWarningHandler::class, 'previous'))->setValue($handler, $previous);

        // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
        $previousReporting = error_reporting(E_ALL);
        try {
            self::assertTrue($handler->handle(E_USER_WARNING, 'Undefined array key "foo"', __FILE__, __LINE__));
            self::assertFalse($calledPrevious);
        } finally {
            // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
            error_reporting($previousReporting);
        }
    }

    public function testHandleChainsWhenContinueFalse(): void
    {
        $calledPrevious = false;
        $previous       = static function () use (&$calledPrevious): bool {
            $calledPrevious = true;

            return true;
        };

        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureMessage');

        $handler = new BeaconPhpWarningHandler($client, true, BeaconPhpWarningHandler::DEFAULT_LEVELS, false);
        (new ReflectionProperty(BeaconPhpWarningHandler::class, 'previous'))->setValue($handler, $previous);

        // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
        $previousReporting = error_reporting(E_ALL);
        try {
            self::assertTrue($handler->handle(E_NOTICE, 'notice', __FILE__, __LINE__));
            self::assertTrue($calledPrevious);
        } finally {
            // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
            error_reporting($previousReporting);
        }
    }

    public function testHandleSkipsSilencedUnlessConfigured(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureMessage');

        $handler = new BeaconPhpWarningHandler($client, true, E_WARNING, true, false);

        // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
        $previousReporting = error_reporting(0);
        try {
            self::assertFalse($handler->handle(E_WARNING, 'silenced', __FILE__, __LINE__));
        } finally {
            // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
            error_reporting($previousReporting);
        }
    }

    public function testHandleCapturesSilencedWhenEnabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureMessage');

        $handler = new BeaconPhpWarningHandler($client, true, E_WARNING, true, true);
        // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
        $previousReporting = error_reporting(0);
        try {
            self::assertTrue($handler->handle(E_WARNING, 'silenced', __FILE__, __LINE__));
        } finally {
            // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
            error_reporting($previousReporting);
        }
    }

    public function testDisabledHandlerDoesNotCapture(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::never())->method('isEnabled');
        $client->expects(self::never())->method('captureMessage');

        $handler = new BeaconPhpWarningHandler($client, false);
        $handler->register();
        self::assertFalse(
            (new ReflectionProperty(BeaconPhpWarningHandler::class, 'registered'))->getValue($handler),
        );
    }

    public function testRegisterIsIdempotentAndUnregisterRestores(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);

        $handler                 = new BeaconPhpWarningHandler($client, true);
        $this->registeredHandler = $handler;
        $handler->register();
        $handler->register();
        self::assertTrue(
            (new ReflectionProperty(BeaconPhpWarningHandler::class, 'registered'))->getValue($handler),
        );
        $handler->unregister();
        $this->registeredHandler = null;
        self::assertFalse(
            (new ReflectionProperty(BeaconPhpWarningHandler::class, 'registered'))->getValue($handler),
        );
    }

    public function testNoticeMapsToInfoLevel(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureMessage')->with(
            self::stringContains('User Notice:'),
            'info',
            self::anything(),
            self::anything(),
        );

        (new BeaconPhpWarningHandler($client))->capture(E_USER_NOTICE, 'n', __FILE__, 1);
    }

    public function testLevelNamesForEveryKind(): void
    {
        $labels = [];
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('captureMessage')->willReturnCallback(static function (string $message) use (&$labels): string {
            $labels[] = $message;

            return 'id';
        });

        $handler = new BeaconPhpWarningHandler($client);
        foreach ([E_USER_DEPRECATED, E_DEPRECATED, E_CORE_WARNING, E_COMPILE_WARNING, 0] as $type) {
            $handler->capture($type, 'm', __FILE__, 1);
        }

        self::assertSame(['User Deprecated: m', 'Deprecated: m', 'Core Warning: m', 'Compile Warning: m', 'Warning: m'], $labels);
    }

    public function testReportingFailureNeverBreaksFlowAndUnregisterIsNoopWhenNotRegistered(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('captureMessage')->willThrowException(new RuntimeException('transport down'));

        $handler = new BeaconPhpWarningHandler($client);
        // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
        $previousReporting = error_reporting(E_ALL);
        try {
            self::assertTrue($handler->handle(E_USER_WARNING, 'boom', __FILE__, __LINE__));
        } finally {
            // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
            error_reporting($previousReporting);
        }

        $handler->unregister();
        self::assertFalse(
            (new ReflectionProperty(BeaconPhpWarningHandler::class, 'registered'))->getValue($handler),
        );
    }

    public function testOneProcessHandlerForwardsToTheLatestRegisteredHandler(): void
    {
        $captured = [];
        $first    = new BeaconPhpWarningHandler($this->recordingClient($captured, 'first'), true);
        $second   = new BeaconPhpWarningHandler($this->recordingClient($captured, 'second'), true);
        $first->register();
        $second->register();
        $this->registeredHandler = $second;

        // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
        $previousReporting = error_reporting(E_ALL);
        try {
            trigger_error('forwarded', E_USER_WARNING);
        } finally {
            // @phpstan-ignore frankenphp.worker.noErrorReportingMutation
            error_reporting($previousReporting);
        }

        self::assertSame(['second: User Warning: forwarded'], $captured);
        $first->unregister();
        self::assertSame($second, (new ReflectionProperty(BeaconPhpWarningHandler::class, 'active'))->getValue());
    }

    public function testDispatchFallsBackToThePreviousHandlerOrFalse(): void
    {
        $calls    = 0;
        $previous = static function () use (&$calls): bool {
            ++$calls;

            return true;
        };
        $processPrevious = new ReflectionProperty(BeaconPhpWarningHandler::class, 'processPrevious');
        $saved           = $processPrevious->getValue();
        try {
            $processPrevious->setValue(null, $previous);
            self::assertTrue(BeaconPhpWarningHandler::dispatch(E_USER_WARNING, 'm', __FILE__, __LINE__));
            $processPrevious->setValue(null, null);
            self::assertFalse(BeaconPhpWarningHandler::dispatch(E_USER_WARNING, 'm', __FILE__, __LINE__));
        } finally {
            $processPrevious->setValue(null, $saved);
        }

        self::assertSame(1, $calls);
    }

    public function testUnregisterLeavesAHandlerPushedLaterInPlace(): void
    {
        $handler = new BeaconPhpWarningHandler(new NullBeaconClient(), true);
        $handler->register();
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static fn (): bool => true);
        try {
            $handler->unregister();
            self::assertNotNull((new ReflectionProperty(BeaconPhpWarningHandler::class, 'installed'))->getValue());
        } finally {
            restore_error_handler();
        }

        // Ours is on top again: a later handler's unregister removes it.
        $later = new BeaconPhpWarningHandler(new NullBeaconClient(), true);
        $later->register();
        $later->unregister();
        self::assertNull((new ReflectionProperty(BeaconPhpWarningHandler::class, 'installed'))->getValue());
    }

    public function testUnregisteredHandlerIsFreed(): void
    {
        $handler = new BeaconPhpWarningHandler(new NullBeaconClient(), true);
        $handler->register();
        $handler->unregister();
        $reference = WeakReference::create($handler);
        unset($handler);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    /**
     * @param list<string> $captured
     */
    private function recordingClient(array &$captured, string $name): BeaconClientInterface
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('captureMessage')->willReturnCallback(static function (string $message) use (&$captured, $name): string {
            $captured[] = $name . ': ' . $message;

            return 'id';
        });

        return $client;
    }
}
