<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Connection;

use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BeaconConnectionTesterTest extends TestCase
{
    public function testEmptyDsnFails(): void
    {
        $tester = $this->createTester('', true, new MockHttpClient());
        $result = $tester->test();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('DSN is empty', $result->getMessage());
        self::assertFalse($result->wasSent());
    }

    public function testUnresolvedEnvPlaceholderFails(): void
    {
        $result = $this->createTester('%env(string:default::BEACON_DSN)%', true, new MockHttpClient())->test();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('DSN is empty', $result->getMessage());
    }

    public function testCheckOnlySucceedsWithoutHttp(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('HTTP must not be called in check-only mode');
        });

        $tester = $this->createTester('https://pubkey123456:secret@beacon.example.com:9444/5', true, $http);
        $result = $tester->test(true);
        $target = $result->getTarget();

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->wasSent());
        self::assertArrayHasKey('origin', $target);
        self::assertArrayHasKey('project_id', $target);
        self::assertArrayHasKey('public_key', $target);
        self::assertSame('https://beacon.example.com:9444', $target['origin']);
        self::assertSame('5', $target['project_id']);
        self::assertStringContainsString('…', $target['public_key']);
        self::assertStringNotContainsString('secret', $result->getMessage());
        self::assertStringNotContainsString('secret', (string) json_encode($target));
    }

    public function testCheckOnlyNotesDisabledReporting(): void
    {
        $result = $this->createTester(
            'https://pk:secret@beacon.example.com/5',
            false,
            new MockHttpClient(static function (): MockResponse {
                self::fail('HTTP must not be called');
            }),
        )->test(true);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('automatic reporting is off', $result->getMessage());
    }

    public function testShortPublicKeyIsNotTruncated(): void
    {
        $result = $this->createTester(
            'https://short:secret@beacon.example.com/5',
            true,
            new MockHttpClient(static function (): MockResponse {
                self::fail('HTTP must not be called');
            }),
        )->test(true);

        self::assertSame('short', $result->getTarget()['public_key'] ?? null);
    }

    public function testSuccessfulProbe(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });

        $tester = $this->createTester('https://pubkey:secret@beacon.example.com:9444/5', true, $http);
        $result = $tester->test(false, 'custom probe');

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->wasSent());
        self::assertSame(200, $result->getHttpStatus());
        self::assertNotNull($result->getEventId());
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://beacon.example.com:9444/api/5/envelope/', $requests[0]['url']);
        self::assertIsString($requests[0]['options']['body']);
        self::assertStringContainsString('custom probe', $requests[0]['options']['body']);
        self::assertStringContainsString('nowo:beacon:test', $requests[0]['options']['body']);
    }

    public function testAuthFailure(): void
    {
        $tester = $this->createTester(
            'https://pubkey:s3cr3t-value@beacon.example.com/5',
            true,
            new MockHttpClient(new MockResponse('', ['http_code' => 403])),
        );
        $result = $tester->test();

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->wasSent());
        self::assertSame(403, $result->getHttpStatus());
        self::assertStringContainsString('authentication', $result->getMessage());
        self::assertStringNotContainsString('s3cr3t-value', $result->getMessage());
        self::assertStringNotContainsString('s3cr3t-value', (string) json_encode($result->getTarget()));
    }

    #[DataProvider('provideRejectedStatuses')]
    public function testRejectedStatusHints(int $status, string $needle): void
    {
        $result = $this->createTester(
            'https://pubkey:secret@beacon.example.com/5',
            true,
            new MockHttpClient(new MockResponse('', ['http_code' => $status])),
        )->test();

        self::assertFalse($result->isSuccess());
        self::assertSame($status, $result->getHttpStatus());
        self::assertStringContainsString($needle, $result->getMessage());
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function provideRejectedStatuses(): iterable
    {
        yield 'unauthorized' => [401, 'authentication'];
        yield 'not found' => [404, 'project id'];
        yield 'rate limited' => [429, 'rate limited'];
        yield 'server error' => [500, 'HTTP 500'];
    }

    public function testTransportFailure(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willThrowException(new class('connection refused') extends RuntimeException implements TransportExceptionInterface {
        });

        $tester = $this->createTester('https://pubkey:secret@beacon.example.com/5', true, $http);
        $result = $tester->test();

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->wasSent());
        self::assertStringContainsString('transport failed', $result->getMessage());
        self::assertStringContainsString('connection refused', $result->getMessage());
    }

    public function testDisabledReportingStillProbesWithNote(): void
    {
        $http   = new MockHttpClient(new MockResponse('', ['http_code' => 202]));
        $tester = $this->createTester('https://pubkey:secret@beacon.example.com/5', false, $http);
        $result = $tester->test();

        self::assertTrue($result->isSuccess());
        self::assertArrayHasKey('reporting_enabled', $result->getTarget());
        self::assertFalse($result->getTarget()['reporting_enabled']);
        self::assertStringContainsString('automatic reporting remains off', $result->getMessage());
    }

    public function testInvalidDsn(): void
    {
        $tester = $this->createTester('https://pubkey:secret@beacon.example.com/not-valid', true, new MockHttpClient());
        $result = $tester->test(true);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Invalid Beacon DSN', $result->getMessage());
    }

    public function testExtractEventIdHandlesMalformedEnvelope(): void
    {
        $tester = $this->createTester('https://pubkey:secret@beacon.example.com/5', true, new MockHttpClient());
        $method = new ReflectionMethod(BeaconConnectionTester::class, 'extractEventId');

        self::assertNull($method->invoke($tester, ''));
        self::assertNull($method->invoke($tester, "not-json\n"));
        self::assertNull($method->invoke($tester, "{\"event_id\":123}\n"));
        self::assertSame('abc', $method->invoke($tester, "{\"event_id\":\"abc\"}\n"));
    }

    private function createTester(string $dsn, bool $reportingEnabled, HttpClientInterface $http): BeaconConnectionTester
    {
        return new BeaconConnectionTester(
            new BeaconDsnParser(),
            $http,
            $reportingEnabled,
            $dsn,
            true,
            2.0,
            'test',
            '1.0.0',
            'ci',
        );
    }
}
