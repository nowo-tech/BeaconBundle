<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Messenger\BeaconTraceStamp;
use Nowo\BeaconBundle\Tests\Fixtures\Kernel\MessengerKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The trace middleware is prepended to `messenger.bus.default` in every configuration, so its service must exist
 * even when the bundle is disabled; before 1.19.2 such a container failed to compile
 * ("Invalid middleware: service BeaconTraceMiddleware not found").
 */
final class DisabledBundleWithMessengerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['BEACON_DSN']);
        (new Filesystem())->remove(MessengerKernel::baseDir());
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, bool}>
     */
    public static function configurations(): iterable
    {
        yield 'enabled false' => [[['enabled' => false, 'dsn' => 'https://pubkey:secret@beacon.example.invalid/1']], false];
        yield 'empty literal DSN' => [[['dsn' => '']], false];
        yield 'no DSN at all' => [[[]], false];
        yield 'when@test override disables an env DSN' => [[['dsn' => '%env(BEACON_DSN)%'], ['enabled' => false]], false];
        yield 'env DSN, empty at runtime' => [[['dsn' => '%env(BEACON_DSN)%']], true];
        yield 'literal DSN' => [[['dsn' => 'https://pubkey:secret@beacon.example.invalid/1']], true];
    }

    /**
     * @param list<array<string, mixed>> $beaconConfigs
     */
    #[DataProvider('configurations')]
    public function testContainerCompilesAndTheBusDispatches(array $beaconConfigs, bool $stamped): void
    {
        // %env(BEACON_DSN)% resolves to an empty DSN (disabled at runtime).
        $_SERVER['BEACON_DSN'] = '';
        $kernel                = new MessengerKernel($beaconConfigs);
        $kernel->boot();

        try {
            $bus = $kernel->getContainer()->get('messenger.default_bus');
            self::assertInstanceOf(MessageBusInterface::class, $bus);

            $envelope = $bus->dispatch(new stdClass());

            // Disabled: the middleware only passes the envelope on. Enabled at compile time: it stamps the trace id.
            self::assertSame($stamped, $envelope->last(BeaconTraceStamp::class) instanceof BeaconTraceStamp);
        } finally {
            $kernel->shutdown();
        }
    }
}
