<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Command;

use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Command\DeployCommand;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeployCommandTest extends TestCase
{
    public function testSendsDeployWithOptionsOrConfiguredDefaults(): void
    {
        $client = new RecordingBeaconClient();
        $tester = new CommandTester(new DeployCommand($client, ' 1.0.0 ', 'prod'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Deploy of 1.0.0 sent (event deploy-id).', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--release'     => '2.0.0',
            '--environment' => 'staging',
            '--commit'      => 'abc123',
            '--url'         => 'https://ci.example.com/1',
            '--name'        => '  ',
        ]));

        self::assertSame([
            ['release' => '1.0.0', 'environment' => 'prod', 'commit' => null, 'url' => null, 'name' => null],
            ['release' => '2.0.0', 'environment' => 'staging', 'commit' => 'abc123', 'url' => 'https://ci.example.com/1', 'name' => null],
        ], $client->deploys);
    }

    public function testFailsWithoutReleaseAndSkipsWhenDisabled(): void
    {
        $noRelease = new CommandTester(new DeployCommand(new RecordingBeaconClient()));
        self::assertSame(Command::FAILURE, $noRelease->execute([]));

        $disabled = new CommandTester(new DeployCommand(new NullBeaconClient(), '1.0.0'));
        self::assertSame(Command::SUCCESS, $disabled->execute([]));
        self::assertStringContainsString('deploy not sent', $disabled->getDisplay());
    }
}
