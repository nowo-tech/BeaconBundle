<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Security;

use Exception;
use LogicException;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteRetryCredentials;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;
use Nowo\BeaconBundle\Tests\Fixtures\Messenger\RemoteRetryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function bin2hex;
use function class_exists;
use function hash;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function str_starts_with;
use function var_dump;
use function var_export;

/**
 * SEC-004 Appendix CH, L5: the DSN secret and the worker key never show in dumps, exports, serialization or traces.
 */
final class SecretHoldersDoNotLeakTest extends TestCase
{
    /** Built at runtime (the same value for providers and tests) so no credential-looking literal is committed. */
    private static function secret(): string
    {
        return 'Fk' . bin2hex(hash('sha256', self::class, true));
    }

    private static function dsn(): string
    {
        return 'https://pubFAKE:' . self::secret() . '@beacon.example.invalid/' . RemoteRetryFixtures::PROJECT;
    }

    /**
     * @return iterable<string, array{object, list<string>}>
     */
    public static function holders(): iterable
    {
        $credentials = new RemoteRetryCredentials(new BeaconDsnParser(), self::dsn(), RemoteRetryFixtures::WORKER_KEY);

        yield 'BeaconDsn' => [(new BeaconDsnParser())->parse(self::dsn()), [self::secret()]];
        yield 'WorkerKey' => [new WorkerKey(RemoteRetryFixtures::WORKER_KEY), [RemoteRetryFixtures::WORKER_KEY]];
        yield 'RemoteRetryCredentials (unresolved)' => [new RemoteRetryCredentials(new BeaconDsnParser(), self::dsn(), RemoteRetryFixtures::WORKER_KEY), [self::secret(), RemoteRetryFixtures::WORKER_KEY]];
        $credentials->problem();
        yield 'RemoteRetryCredentials (resolved)' => [$credentials, [self::secret(), RemoteRetryFixtures::WORKER_KEY]];
        yield 'BeaconConnectionTester' => [new BeaconConnectionTester(new BeaconDsnParser(), new MockHttpClient(), true, self::dsn()), [self::secret()]];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('holders')]
    public function testDumpsAndExportsHideTheSecrets(object $holder, array $secrets): void
    {
        ob_start();
        var_dump($holder);
        $outputs = [
            'var_dump'   => (string) ob_get_clean(),
            'print_r'    => print_r($holder, true),
            'var_export' => var_export($holder, true),
            'json'       => (string) json_encode($holder),
        ];
        if (class_exists(VarCloner::class)) {
            $outputs['VarDumper'] = (string) (new CliDumper())->dump((new VarCloner())->cloneVar($holder), true);
        }

        foreach ($outputs as $how => $output) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $output, $how);
            }
        }
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('holders')]
    public function testSerializationIsRefused(object $holder, array $secrets): void
    {
        try {
            $serialized = serialize($holder);
        } catch (Exception $e) {
            self::assertStringNotContainsString($secrets[0], $e->getMessage());

            return;
        }

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
        self::fail('serialize() must be refused.');
    }

    public function testExplicitSerializeHooksThrow(): void
    {
        $this->expectException(LogicException::class);
        (new BeaconDsnParser())->parse(self::dsn())->__serialize();
    }

    public function testWorkerKeySerializeHookThrows(): void
    {
        $this->expectException(LogicException::class);
        (new WorkerKey(RemoteRetryFixtures::WORKER_KEY))->__serialize();
    }

    public function testCredentialsSerializeHookThrows(): void
    {
        $this->expectException(LogicException::class);
        (new RemoteRetryCredentials(new BeaconDsnParser(), self::dsn(), RemoteRetryFixtures::WORKER_KEY))->__serialize();
    }

    public function testInvalidDsnNeverAppearsInTheMessageOrTheTraceArguments(): void
    {
        $invalid = 'https://pubFAKE:' . self::secret() . '@beacon.example.invalid/not-a-project';

        try {
            (new BeaconClientFactory(new BeaconDsnParser(), new MockHttpClient()))->create(true, $invalid, 'prod', null, 'h', true, 1.0);
            self::fail('The DSN is invalid.');
        } catch (InvalidBeaconDsnException $e) {
            self::assertStringNotContainsString(self::secret(), $e->getMessage());
            self::assertStringNotContainsString(self::secret(), $e->getTraceAsString());
            $bundleFrames = 0;
            foreach ($e->getTrace() as $frame) {
                if (!str_starts_with($frame['class'] ?? '', 'Nowo\\BeaconBundle\\') || str_starts_with($frame['class'] ?? '', 'Nowo\\BeaconBundle\\Tests\\')) {
                    continue;
                }
                ++$bundleFrames;
                self::assertStringNotContainsString(self::secret(), print_r($frame['args'] ?? [], true), $frame['function']);
            }
            self::assertGreaterThanOrEqual(2, $bundleFrames, 'parse() and create() frames are inspected');
        }
    }
}
