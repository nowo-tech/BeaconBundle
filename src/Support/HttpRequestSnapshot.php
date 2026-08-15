<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Support;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

use function array_keys;
use function is_array;
use function is_object;
use function is_string;

/**
 * Safe HTTP request snapshot for Beacon extras / request context.
 *
 * @phpstan-type HttpExtra array{
 *     route?: string,
 *     controller?: string,
 *     status_code?: int,
 *     query_keys?: list<string>,
 *     client?: array{ip?: string, user_agent?: string}
 * }
 */
final class HttpRequestSnapshot
{
    /**
     * @return HttpExtra
     */
    public static function fromRequest(Request $request, ?Throwable $throwable = null, bool $includeClient = false): array
    {
        $http = [];

        $route = $request->attributes->get('_route');
        if (is_string($route) && $route !== '') {
            $http['route'] = $route;
        }

        $controller = $request->attributes->get('_controller');
        if (is_string($controller) && $controller !== '') {
            $http['controller'] = $controller;
        } elseif (is_array($controller) && $controller !== []) {
            $http['controller'] = self::stringifyController($controller);
        }

        $queryKeys = array_keys($request->query->all());
        if ($queryKeys !== []) {
            $http['query_keys'] = $queryKeys;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $http['status_code'] = $throwable->getStatusCode();
        }

        if ($includeClient) {
            $client = [];
            $ip     = $request->getClientIp();
            if (is_string($ip) && $ip !== '') {
                $client['ip'] = $ip;
            }
            $ua = $request->headers->get('User-Agent');
            if (is_string($ua) && $ua !== '') {
                $client['user_agent'] = $ua;
            }
            if ($client !== []) {
                $http['client'] = $client;
            }
        }

        return $http;
    }

    /**
     * @param array<mixed> $controller
     */
    private static function stringifyController(array $controller): string
    {
        $class  = $controller[0] ?? null;
        $method = $controller[1] ?? null;
        if (is_object($class)) {
            $class = $class::class;
        }
        if (is_string($class) && is_string($method)) {
            return $class . '::' . $method;
        }
        if (is_string($class)) {
            return $class;
        }

        return 'array';
    }
}
