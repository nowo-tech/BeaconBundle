<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Scheduler;

use DateTimeImmutable;
use LogicException;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\BrokenScheduleProvider;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\FixedScheduleProvider;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\OptedOutMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\PlainMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\SlowSyncMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use stdClass;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CallbackMessageProvider;
use Symfony\Component\Scheduler\Trigger\ExcludeTimeTrigger;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

use function array_keys;
use function array_slice;
use function json_encode;

use const STR_PAD_LEFT;

final class ScheduleCatalogBuilderTest extends TestCase
{
    private const NOW = '2026-10-17T10:07:30+00:00';

    public function testBuildsCronPeriodicDecoratedAndCallbackEntries(): void
    {
        $periodic        = RecurringMessage::every(900, new SlowSyncMessage());
        $callbackInvoked = false;
        $callback        = RecurringMessage::trigger(
            new PeriodicalTrigger(3600, new DateTimeImmutable('2026-10-17T00:00:00+00:00')),
            new CallbackMessageProvider(static function () use (&$callbackInvoked): array {
                $callbackInvoked = true;

                return [new PlainMessage()];
            }, 'dynamic'),
        );

        $schedule = (new Schedule())->add(
            RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:reports:nightly --token=s3cr3t'), 'Europe/Madrid'),
            RecurringMessage::cron('*/5 * * * *', new PlainMessage('s3cr3t'))->withJitter(30),
            $periodic,
            RecurringMessage::trigger(
                new ExcludeTimeTrigger(new PeriodicalTrigger(60, new DateTimeImmutable('2026-10-17T10:00:00+00:00')), new DateTimeImmutable('2026-10-17T22:00:00+00:00'), new DateTimeImmutable('2026-10-18T06:00:00+00:00')),
                new PlainMessage(),
            ),
            $callback,
            RecurringMessage::every(60, new OptedOutMessage()),
            RecurringMessage::trigger(new PeriodicalTrigger(60), new StaticMessageProvider([], 'empty')),
            RecurringMessage::every(60, new stdClass(), '2020-01-01T00:00:00+00:00', '2020-01-02T00:00:00+00:00'),
        );

        $builder = $this->builder(['default' => new FixedScheduleProvider($schedule)], new CheckInPolicy(['app:reports:nightly' => 1800]));
        $catalog = $builder->build();

        self::assertCount(1, $catalog);
        self::assertSame('default', $catalog[0]['name']);
        $entries = $catalog[0]['entries'];
        self::assertCount(7, $entries, 'opted-out message is not listed');

        [$cron, $jitter, $every, $excluded, $dynamic, $empty, $expired] = $entries;

        self::assertSame(RunCommandMessage::class, $cron['message_class']);
        self::assertSame('app:reports:nightly', $cron['command']);
        self::assertSame(
            ['kind' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Madrid', 'interval_seconds' => null, 'description' => '0 3 * * *'],
            $cron['trigger'],
        );
        self::assertSame('2026-10-18T01:00:00+00:00', $cron['next_run_at']);
        self::assertSame(1800, $cron['max_runtime_seconds']);
        self::assertNull($cron['description'], 'no command loader in this builder');
        self::assertSame(['recurring_id', 'message_class', 'command', 'description', 'trigger', 'next_run_at', 'max_runtime_seconds'], array_keys($cron));

        self::assertSame('cron', $jitter['trigger']['kind']);
        self::assertSame(date_default_timezone_get(), $jitter['trigger']['timezone']);
        self::assertStringContainsString('jitter', $jitter['trigger']['description']);
        self::assertArrayNotHasKey('command', $jitter);
        self::assertNull($jitter['max_runtime_seconds']);
        self::assertIsString($jitter['next_run_at']);

        self::assertSame(SlowSyncMessage::class, $every['message_class']);
        self::assertSame(['kind' => 'periodic', 'expression' => null, 'timezone' => null, 'interval_seconds' => 900, 'description' => 'every 900 seconds'], $every['trigger']);
        self::assertSame('2026-10-17T10:22:30+00:00', $every['next_run_at']);
        self::assertSame(600, $every['max_runtime_seconds']);
        self::assertSame('Syncs the product catalog from the ERP.', $every['description']);
        self::assertSame('Sends the weekly digest e-mail to subscribers.', $jitter['description']);
        self::assertNull(
            (new ReflectionProperty(PeriodicalTrigger::class, 'from'))->getValue($periodic->getTrigger()),
            'the real periodic trigger is never anchored by the catalog',
        );

        self::assertSame('periodic', $excluded['trigger']['kind']);
        self::assertSame(60, $excluded['trigger']['interval_seconds']);
        self::assertSame('2026-10-17T10:08:00+00:00', $excluded['next_run_at']);

        self::assertNull($dynamic['message_class']);
        self::assertNull($dynamic['description']);
        self::assertArrayHasKey('description', $empty);
        self::assertSame('2026-10-17T11:00:00+00:00', $dynamic['next_run_at']);
        self::assertFalse($callbackInvoked, 'callback providers are never invoked');

        self::assertNull($empty['message_class']);

        self::assertNull($expired['next_run_at']);
        self::assertNull($expired['trigger']['interval_seconds']);

        $json = (string) json_encode($catalog);
        self::assertStringNotContainsString('s3cr3t', $json, 'never message payloads or command arguments');
        self::assertStringNotContainsString('--token', $json);
    }

    public function testOtherTriggersLimitsFiltersAndBrokenProviders(): void
    {
        $weird = new class implements TriggerInterface, Stringable {
            public function __toString(): string
            {
                return 'when the moon is full';
            }

            public function getNextRunDate(DateTimeImmutable $run): ?DateTimeImmutable
            {
                throw new LogicException('no next run');
            }
        };

        $many = new Schedule();
        for ($i = 1; $i <= ScheduleCatalogBuilder::MAX_ENTRIES + 5; ++$i) {
            $many->add(RecurringMessage::every($i, new PlainMessage()));
        }

        $providers = [
            'broken'       => new BrokenScheduleProvider(),
            'many'         => new FixedScheduleProvider($many),
            'notaprovider' => new stdClass(),
            'weird'        => new FixedScheduleProvider((new Schedule())->add(RecurringMessage::trigger($weird, new PlainMessage()))),
        ];
        for ($i = 0; $i < ScheduleCatalogBuilder::MAX_SCHEDULES + 3; ++$i) {
            $providers['s' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = new FixedScheduleProvider(new Schedule());
        }

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };

        $builder = $this->builder($providers, new CheckInPolicy(), $logger);

        self::assertSame(['broken', 'many', 'notaprovider', 's00'], array_slice($builder->scheduleNames(), 0, 4));

        $only = $builder->build(['broken', 'many', 'notaprovider', 'weird']);
        self::assertSame(['many', 'weird'], array_column($only, 'name'));
        self::assertCount(ScheduleCatalogBuilder::MAX_ENTRIES, $only[0]['entries']);
        self::assertCount(1, $logger->records);

        $other = $only[1]['entries'][0];
        self::assertSame('other', $other['trigger']['kind']);
        self::assertSame('when the moon is full', $other['trigger']['description']);
        self::assertNull($other['next_run_at']);

        self::assertCount(ScheduleCatalogBuilder::MAX_SCHEDULES, $builder->build());

        self::assertSame([], (new ScheduleCatalogBuilder(new CheckInPolicy()))->scheduleNames());
        self::assertSame([], (new ScheduleCatalogBuilder(new CheckInPolicy()))->build());
    }

    public function testDefaultClockIsUsedWhenNoneGiven(): void
    {
        $builder = new ScheduleCatalogBuilder(
            new CheckInPolicy(),
            new ServiceLocator(['default' => static fn (): FixedScheduleProvider => new FixedScheduleProvider((new Schedule())->add(RecurringMessage::every(60, new PlainMessage())))]),
        );

        $next = $builder->build()[0]['entries'][0]['next_run_at'];
        self::assertIsString($next);
        self::assertGreaterThan(new DateTimeImmutable('-1 minute'), new DateTimeImmutable($next));
    }

    /**
     * @param array<string, object> $providers
     */
    private function builder(array $providers, CheckInPolicy $policy, ?AbstractLogger $logger = null): ScheduleCatalogBuilder
    {
        $factories = [];
        foreach ($providers as $name => $provider) {
            $factories[$name] = static fn (): object => $provider;
        }

        return new ScheduleCatalogBuilder($policy, new ServiceLocator($factories), new MockClock(self::NOW), $logger);
    }
}
