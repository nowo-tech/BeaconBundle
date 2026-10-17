<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Scheduler;

use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\AttributeWithoutMaxRuntimeMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\BrokenAttributeMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\OptedOutMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\OutOfRangeMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\PlainMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\ReportMessageInterface;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\SlowSyncMessage;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;

final class CheckInPolicyTest extends TestCase
{
    public function testDescribeReadsOnlyClassAndCommandName(): void
    {
        self::assertSame(
            ['message_class' => PlainMessage::class, 'command' => null],
            CheckInPolicy::describe(new PlainMessage('s3cr3t')),
        );
        self::assertSame(
            ['message_class' => RunCommandMessage::class, 'command' => 'app:reports:nightly'],
            CheckInPolicy::describe(new RunCommandMessage('app:reports:nightly --token=s3cr3t "quoted arg"')),
        );
        self::assertSame(
            ['message_class' => RunCommandMessage::class, 'command' => 'app:quoted'],
            CheckInPolicy::describe(new RunCommandMessage("'app:quoted' x")),
        );
        self::assertSame(
            ['message_class' => RunCommandMessage::class, 'command' => null],
            CheckInPolicy::describe(new RunCommandMessage('   ')),
        );
        // RedispatchMessage is unwrapped (bare message and Envelope).
        self::assertSame(
            ['message_class' => RunCommandMessage::class, 'command' => 'app:sync'],
            CheckInPolicy::describe(new RedispatchMessage(new RunCommandMessage('app:sync'), 'async')),
        );
        self::assertSame(
            ['message_class' => PlainMessage::class, 'command' => null],
            CheckInPolicy::describe(new RedispatchMessage(new Envelope(new PlainMessage()), ['async'])),
        );
        self::assertSame(120, mb_strlen((string) CheckInPolicy::commandName(str_repeat('a', 300))));
    }

    public function testAttributeWinsOverConfig(): void
    {
        $policy = new CheckInPolicy(
            [SlowSyncMessage::class => 30, OptedOutMessage::class => 30],
            [SlowSyncMessage::class],
        );

        self::assertTrue($policy->isEnabled(SlowSyncMessage::class));
        self::assertSame(600, $policy->maxRuntime(SlowSyncMessage::class));
        self::assertFalse($policy->isEnabled(OptedOutMessage::class));
        // Out-of-range attribute value is ignored (no limit).
        self::assertNull($policy->maxRuntime(OutOfRangeMessage::class));
        // Attribute without maxRuntime falls back to config.
        $withConfig = new CheckInPolicy([AttributeWithoutMaxRuntimeMessage::class => 45]);
        self::assertSame(45, $withConfig->maxRuntime(AttributeWithoutMaxRuntimeMessage::class));
        // Broken attribute (TypeError on instantiation) is ignored.
        self::assertTrue($withConfig->isEnabled(BrokenAttributeMessage::class));
        self::assertNull($withConfig->maxRuntime(BrokenAttributeMessage::class));
    }

    public function testConfigMatchesCommandClassAndInterface(): void
    {
        $policy = new CheckInPolicy(
            [
                'app:reports:nightly'         => 1800,
                RunCommandMessage::class      => 60,
                ReportMessageInterface::class => 120,
                'app:too-long'                => 999999999,
            ],
            ['app:noisy', stdClass::class],
        );

        self::assertSame(1800, $policy->maxRuntime(RunCommandMessage::class, 'app:reports:nightly'));
        self::assertSame(60, $policy->maxRuntime(RunCommandMessage::class, 'app:other'));
        self::assertSame(120, $policy->maxRuntime(PlainMessage::class));
        self::assertNull($policy->maxRuntime(RunCommandMessage::class . 'Missing'));
        self::assertNull($policy->maxRuntime(RunCommandMessage::class, 'app:too-long'));

        self::assertFalse($policy->isEnabled(RunCommandMessage::class, 'app:noisy'));
        self::assertFalse($policy->isEnabled(stdClass::class));
        self::assertTrue($policy->isEnabled(RunCommandMessage::class, 'app:reports:nightly'));
        self::assertTrue($policy->isEnabled('App\\Missing\\ClassName'));

        $byInterface = new CheckInPolicy([], [ReportMessageInterface::class]);
        self::assertFalse($byInterface->isEnabled(PlainMessage::class));
        // Cached attribute lookups return the same answer.
        self::assertFalse($byInterface->isEnabled(PlainMessage::class));
    }
}
