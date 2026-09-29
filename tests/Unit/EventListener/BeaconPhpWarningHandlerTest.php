<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use const E_ALL;
use const E_NOTICE;
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
}
