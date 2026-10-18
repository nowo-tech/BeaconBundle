<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Closure;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconRequestTransactionListener;
use Nowo\BeaconBundle\Support\IgnoredRequestPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * `ignore_routes` and `http_transaction_sample_rate` of the auto HTTP transaction listener.
 */
final class BeaconRequestTransactionListenerFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: int}>
     */
    public static function routeProvider(): iterable
    {
        yield 'ignored prefix' => ['ingest_envelope', 0];
        yield 'second ignored prefix' => ['api_poll_commands', 0];
        yield 'other route' => ['dashboard_home', 1];
        yield 'prefix only in the middle' => ['admin_ingest_settings', 1];
        yield 'no route (404)' => [null, 1];
    }

    #[DataProvider('routeProvider')]
    public function testIgnoreRoutesSkipsRouteNamePrefixes(?string $route, int $expectedTransactions): void
    {
        $listener = $this->listener($expectedTransactions, ignoreRoutes: ['', 'ingest_', 'api_poll_']);

        $this->dispatch($listener, $route);
    }

    public function testSampleRateZeroNeverSends(): void
    {
        $this->dispatch($this->listener(0, sampleRate: 0.0, random: static fn (): float => 0.0), 'dashboard_home');
    }

    public function testSampleRateKeepsDrawsBelowTheRate(): void
    {
        $this->dispatch($this->listener(1, sampleRate: 0.5, random: static fn (): float => 0.49), 'dashboard_home');
    }

    public function testSampleRateDropsDrawsAtOrAboveTheRate(): void
    {
        $this->dispatch($this->listener(0, sampleRate: 0.5, random: static fn (): float => 0.5), 'dashboard_home');
    }

    public function testSampleRateAboveOneIsClampedAndUsesTheDefaultRandom(): void
    {
        $this->dispatch($this->listener(1, sampleRate: 7.0), 'dashboard_home');
    }

    public function testDefaultRandomIsUsedBelowFullRate(): void
    {
        $sent   = 0;
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('captureTransaction')->willReturnCallback(static function () use (&$sent): ?string {
            ++$sent;

            return null;
        });

        $listener = new BeaconRequestTransactionListener($client, true, IgnoredRequestPath::DEFAULTS, 0.999999);
        for ($i = 0; $i < 20; ++$i) {
            $this->dispatch($listener, 'dashboard_home');
        }

        self::assertGreaterThan(0, $sent);
    }

    /**
     * @param list<string> $ignoreRoutes
     * @param (Closure(): float)|null $random
     */
    private function listener(int $expected, float $sampleRate = 1.0, array $ignoreRoutes = [], ?Closure $random = null): BeaconRequestTransactionListener
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::exactly($expected))->method('captureTransaction');

        return new BeaconRequestTransactionListener($client, true, IgnoredRequestPath::DEFAULTS, $sampleRate, $ignoreRoutes, $random);
    }

    private function dispatch(BeaconRequestTransactionListener $listener, ?string $route): void
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/some/page');
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }
}
