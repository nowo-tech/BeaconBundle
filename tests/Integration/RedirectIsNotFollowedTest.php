<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandClient;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandException;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\NativeHttpClient;

use function fclose;
use function file;
use function fsockopen;
use function is_resource;
use function json_decode;
use function proc_open;
use function proc_terminate;
use function stream_socket_get_name;
use function stream_socket_server;
use function tempnam;
use function unlink;
use function usleep;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const PHP_BINARY;

/**
 * Real HTTP round trip: Beacon (here a `php -S` router) answers 307 to another host name. The bundle's outbound
 * clients must not follow it, so neither `X-Beacon-Auth` nor the worker `Authorization` reaches the other host.
 */
final class RedirectIsNotFollowedTest extends TestCase
{
    /** @var resource|null */
    private $server;

    private string $log = '';

    private int $port = 0;

    protected function setUp(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);

        $this->log = (string) tempnam(sys_get_temp_dir(), 'beacon-redirect-');
        // The server is stopped in tearDown(); the readiness loop below is bounded (5 s).
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/../Fixtures/Http/redirect-router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['BEACON_REDIRECT_LOG' => $this->log],
        ) ?: null;
        self::assertIsResource($this->server);

        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $this->port);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
        }
        @unlink($this->log);
    }

    public function testEnvelopeTransportDoesNotFollowTheRedirect(): void
    {
        $transport = new EnvelopeTransport(
            new NativeHttpClient(),
            (new BeaconDsnParser())->parse('http://pubkey:secret@127.0.0.1:' . $this->port . '/5'),
        );

        $result = $transport->sendDetailed("envelope\n");

        self::assertFalse($result->isAccepted());
        self::assertSame(307, $result->getStatusCode());
        self::assertSame([['host' => '127.0.0.1:' . $this->port, 'path' => '/api/5/envelope/']], $this->requests());
    }

    public function testRemoteCommandClientDoesNotFollowTheRedirect(): void
    {
        $client = new RemoteCommandClient(
            new NativeHttpClient(),
            RemoteRetryFixtures::credentials('http://pubkey:secret@127.0.0.1:' . $this->port . '/5'),
            'worker-1',
            'test',
        );

        try {
            $client->claim([]);
            self::fail('A redirect must not be treated as a claim response.');
        } catch (RemoteCommandException $e) {
            self::assertSame(307, $e->statusCode);
        }

        $requests = $this->requests();
        self::assertCount(1, $requests);
        self::assertSame('127.0.0.1:' . $this->port, $requests[0]['host']);
    }

    /**
     * @return list<array{host: string, path: string}>
     */
    private function requests(): array
    {
        $requests = [];
        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{host: string, path: string} $request */
            $request    = json_decode($line, true);
            $requests[] = ['host' => $request['host'], 'path' => $request['path']];
        }

        return $requests;
    }
}
