<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Support;

use LogicException;
use Nowo\BeaconBundle\Support\IgnoredException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IgnoredExceptionTest extends TestCase
{
    public function testMatchesConfiguredClassesAndInterfaces(): void
    {
        self::assertTrue(IgnoredException::matches(new RuntimeException(), [RuntimeException::class]));
        self::assertTrue(IgnoredException::matches(new NotFoundHttpException(), [HttpExceptionInterface::class]));
        self::assertFalse(IgnoredException::matches(new RuntimeException(), [LogicException::class]));
        self::assertFalse(IgnoredException::matches(new RuntimeException(), []));
    }

    public function testSkipsNonStringAndEmptyEntries(): void
    {
        self::assertFalse(IgnoredException::matches(new RuntimeException(), ['', 42, null]));
        self::assertTrue(IgnoredException::matches(new RuntimeException(), ['', RuntimeException::class]));
    }

    public function testHttpClientErrorsOnlyWhenEnabled(): void
    {
        self::assertFalse(IgnoredException::matches(new NotFoundHttpException(), []));
        self::assertTrue(IgnoredException::matches(new NotFoundHttpException(), [], true));
        self::assertTrue(IgnoredException::matches(new AccessDeniedHttpException(), [], true));
        self::assertTrue(IgnoredException::matches(new HttpException(499), [], true));
        self::assertFalse(IgnoredException::matches(new HttpException(500), [], true));
        self::assertFalse(IgnoredException::matches(new HttpException(503), [], true));
        self::assertFalse(IgnoredException::matches(new RuntimeException(), [], true));
    }

    public function testIsHttpClientError(): void
    {
        self::assertTrue(IgnoredException::isHttpClientError(new HttpException(400)));
        self::assertFalse(IgnoredException::isHttpClientError(new HttpException(500)));
        self::assertFalse(IgnoredException::isHttpClientError(new RuntimeException('404', 404)));
    }
}
