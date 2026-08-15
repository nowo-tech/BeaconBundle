<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Command;

use Nowo\BeaconBundle\Command\TestConnectionCommand;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TestConnectionCommandTest extends TestCase
{
    public function testCommandSucceedsOnAcceptedIngest(): void
    {
        $tester = new BeaconConnectionTester(
            new BeaconDsnParser(),
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
            true,
            'https://pubkey:secret@beacon.example.com/5',
        );
        $command       = new TestConnectionCommand($tester);
        $commandTester = new CommandTester($command);

        $exit = $commandTester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('accepted the test envelope', $commandTester->getDisplay());
        self::assertStringContainsString('beacon.example.com', $commandTester->getDisplay());
        self::assertStringNotContainsString('secret', $commandTester->getDisplay());
    }

    public function testCheckOnlyDoesNotSend(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('HTTP must not be called');
        });
        $commandTester = new CommandTester(new TestConnectionCommand(new BeaconConnectionTester(
            new BeaconDsnParser(),
            $http,
            true,
            'https://pubkey:secret@beacon.example.com/5',
        )));

        self::assertSame(0, $commandTester->execute(['--check-only' => true]));
        self::assertStringContainsString('DSN is valid', $commandTester->getDisplay());
    }

    public function testCommandFailsOnEmptyDsn(): void
    {
        $commandTester = new CommandTester(new TestConnectionCommand(new BeaconConnectionTester(
            new BeaconDsnParser(),
            new MockHttpClient(),
            false,
            '',
        )));

        self::assertSame(1, $commandTester->execute([]));
        self::assertStringContainsString('DSN is empty', $commandTester->getDisplay());
    }

    public function testCommandPassesCustomMessage(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });
        $commandTester = new CommandTester(new TestConnectionCommand(new BeaconConnectionTester(
            new BeaconDsnParser(),
            $http,
            true,
            'https://pubkey:secret@beacon.example.com/5',
        )));

        self::assertSame(0, $commandTester->execute(['--message' => 'from-test']));
        self::assertStringContainsString('from-test', $requests[0]['body']);
        self::assertStringContainsString('Event id:', $commandTester->getDisplay());
        self::assertStringContainsString('HTTP status:', $commandTester->getDisplay());
    }
}
