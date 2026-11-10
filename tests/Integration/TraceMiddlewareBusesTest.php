<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Messenger\BeaconTraceMiddleware;
use Nowo\BeaconBundle\Messenger\BeaconTraceStamp;
use Nowo\BeaconBundle\Tests\Fixtures\Kernel\MessengerKernel;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `nowo_beacon.messenger.trace_middleware` (1.22+): which buses get {@see BeaconTraceMiddleware}.
 */
final class TraceMiddlewareBusesTest extends TestCase
{
    private const DSN = 'https://pubkey:secret@beacon.example.invalid/1';

    protected function tearDown(): void
    {
        (new Filesystem())->remove(MessengerKernel::baseDir());
    }

    public function testDefaultStampsOnlyTheDefaultBus(): void
    {
        $this->withBuses([['dsn' => self::DSN]], ['command.bus'], static function (ContainerInterface $c): void {
            self::assertTrue(self::stamped($c, 'messenger.bus.default'));
            self::assertFalse(self::stamped($c, 'command.bus'));
        });
    }

    public function testDisabledLeavesEveryBusUntouched(): void
    {
        $config = ['dsn' => self::DSN, 'messenger' => ['trace_middleware' => ['enabled' => false]]];
        $this->withBuses([$config], ['command.bus'], static function (ContainerInterface $c): void {
            self::assertFalse(self::stamped($c, 'messenger.bus.default'));
            self::assertFalse(self::stamped($c, 'command.bus'));
        });
    }

    public function testListedBusesGetTheMiddleware(): void
    {
        $config = ['dsn' => self::DSN, 'messenger' => ['trace_middleware' => ['buses' => ['command.bus', 'event.bus']]]];
        $this->withBuses([$config], ['command.bus', 'event.bus'], static function (ContainerInterface $c): void {
            self::assertFalse(self::stamped($c, 'messenger.bus.default'));
            self::assertTrue(self::stamped($c, 'command.bus'));
            self::assertTrue(self::stamped($c, 'event.bus'));
        });
    }

    /**
     * @param list<array<string, mixed>> $beaconConfigs
     * @param list<string> $extraBuses
     * @param callable(ContainerInterface): void $assert
     */
    private function withBuses(array $beaconConfigs, array $extraBuses, callable $assert): void
    {
        $kernel = new MessengerKernel($beaconConfigs, 'test', $extraBuses);
        $kernel->boot();

        try {
            $testContainer = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $testContainer);
            $assert($testContainer);
        } finally {
            $kernel->shutdown();
        }
    }

    private static function stamped(ContainerInterface $container, string $busId): bool
    {
        $bus = $container->get($busId);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch(new stdClass())->last(BeaconTraceStamp::class) instanceof BeaconTraceStamp;
    }
}
