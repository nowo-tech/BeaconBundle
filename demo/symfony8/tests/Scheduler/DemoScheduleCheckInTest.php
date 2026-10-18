<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DemoScheduleCheckInTest extends KernelTestCase
{
    public function testCatalogListsTheDemoScheduleWithoutArguments(): void
    {
        $application = new Application(self::bootKernel());
        $tester      = new CommandTester($application->find('nowo:beacon:scheduler-catalog'));

        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();

        self::assertStringContainsString('app:demo-nightly-report', $display);
        self::assertStringNotContainsString('--since', $display);
        self::assertStringContainsString('App\\Message\\DemoSlowSyncMessage', $display);
        self::assertStringContainsString('5 s', $display);
        self::assertStringContainsString('600 s', $display);
        self::assertStringContainsString('Dry run: catalog not sent.', $display);

        // Task descriptions (1.16+): config map, console command description, class docblock summary.
        self::assertStringContainsString('Proves the scheduler worker is alive every 30 seconds.', $display);
        self::assertStringContainsString('Demo nightly report scheduled through Symfony Scheduler', $display);
        self::assertStringContainsString('Scheduled task whose handler always throws', $display);
    }
}
