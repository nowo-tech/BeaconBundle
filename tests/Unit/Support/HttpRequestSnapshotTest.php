<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Support;

use Nowo\BeaconBundle\Support\HttpRequestSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HttpRequestSnapshotTest extends TestCase
{
    public function testCapturesRouteControllerQueryKeysAndStatus(): void
    {
        $request = Request::create('/projects/1/issues?status=open&q=boom', 'GET');
        $request->attributes->set('_route', 'issue_show');
        $request->attributes->set('_controller', 'App\\Issues\\Controller\\IssueController::show');

        $http = HttpRequestSnapshot::fromRequest($request, new NotFoundHttpException('missing'), true);

        self::assertSame('issue_show', $http['route'] ?? null);
        self::assertSame('App\\Issues\\Controller\\IssueController::show', $http['controller'] ?? null);
        self::assertSame(404, $http['status_code'] ?? null);
        self::assertSame(['status', 'q'], $http['query_keys'] ?? null);
        self::assertIsArray($http['client'] ?? null);
        self::assertArrayHasKey('ip', $http['client'] ?? []);
    }

    public function testOmitsClientWhenDisabled(): void
    {
        $http = HttpRequestSnapshot::fromRequest(Request::create('/'), null, false);
        self::assertArrayNotHasKey('client', $http);
    }
}
