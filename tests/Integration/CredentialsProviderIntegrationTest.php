<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use DateTimeImmutable;
use LogicException;
use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\ResolvingBeaconClient;
use Nowo\BeaconBundle\Command\RotateCredentialsCommand;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\InMemoryCredentialsStore;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use Nowo\BeaconBundle\Tests\Fixtures\CredentialsKernel\CredentialsKernel;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function intdiv;
use function memory_get_usage;

/**
 * 1.25 credentials provider in real kernels: a DDD host (own plain-PHP adapter) and a standard host (InstanceSettings
 * bridge), `lint:container`, and REQ-RUNTIME-002 (one kept-alive kernel, no resetter, DSN rotated and projects alternated
 * over 1,200 requests, memory flat).
 */
#[CoversNothing]
final class CredentialsProviderIntegrationTest extends TestCase
{
    private const ITERATIONS = 1200;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private ?CredentialsKernel $kernel = null;

    private ?ContainerInterface $container = null;

    protected function tearDown(): void
    {
        // lint:container re-instantiates the bundles, so Kernel::shutdown() no longer reaches the ones that registered
        // the PHP error handlers: release them here.
        $container = $this->container;
        if ($container instanceof ContainerInterface) {
            foreach ([BeaconFatalErrorHandler::class, BeaconPhpWarningHandler::class] as $id) {
                $handler = $container->initialized($id) ? $container->get($id) : null;
                if ($handler instanceof BeaconFatalErrorHandler || $handler instanceof BeaconPhpWarningHandler) {
                    $handler->unregister();
                }
            }
        }

        $this->kernel?->shutdown();
        $this->kernel    = null;
        $this->container = null;
        (new Filesystem())->remove(CredentialsKernel::baseDir());
    }

    public function testDddHostWithItsOwnAdapter(): void
    {
        $container = $this->boot('ddd');
        self::assertSame(0, $this->lint());
        self::assertInstanceOf(ResolvingBeaconClient::class, $container->get(BeaconClientInterface::class));

        $store  = $this->store($container);
        $ingest = $this->ingest($container);

        $this->request($container, 0);
        self::assertSame([], $ingest->calls, 'empty BEACON_DSN and nothing stored: events are dropped');

        $store->record = new BeaconCredentials(F::DB_DSN);
        $this->request($container, 1);
        self::assertSame(['pub-db'], $ingest->publicKeys());
    }

    public function testStandardHostWithTheInstanceSettingsBridge(): void
    {
        $container = $this->boot('standard', ['credentials_cache_ttl' => 3600]);
        self::assertSame(0, $this->lint());
        $settings = $container->get(InstanceSettingsInterface::class);
        self::assertInstanceOf(InstanceSettingsInterface::class, $settings);
        $ingest = $this->ingest($container);

        $this->request($container, 0);
        self::assertSame([], $ingest->calls);

        // Saved from the admin form: SettingsChangedEvent expires the 1 h cache at once.
        $settings->save('beacon', ['dsn' => F::DB_DSN], 'form');
        $this->request($container, 1);
        self::assertSame(['pub-db'], $ingest->publicKeys());

        $rotate = $container->get(RotateCredentialsCommand::class);
        self::assertInstanceOf(RotateCredentialsCommand::class, $rotate);
        $tester = new CommandTester($rotate);
        $tester->setInputs([F::NEXT_DSN]);
        self::assertSame(0, $tester->execute(['--dsn' => true, '--grace' => '600']), $tester->getDisplay());
        self::assertSame(F::NEXT_DSN, $settings->getSecret('beacon.dsn'));
        self::assertSame(F::DB_DSN, $settings->getSecret('beacon.previous_dsn'));
        self::assertNotNull($settings->getString('beacon.previous_dsn_expires_at'));

        $ingest->calls = [];
        $this->request($container, 2);
        self::assertSame(['pub-next'], $ingest->publicKeys());
    }

    public function testMessengerModeHandlerUsesTheStoredDsn(): void
    {
        $container                       = $this->boot('ddd', ['transport' => ['mode' => 'messenger']]);
        $this->store($container)->record = new BeaconCredentials(F::DB_DSN);
        $this->request($container, 0);

        self::assertSame(['pub-db'], $this->ingest($container)->publicKeys());
    }

    public function testWithoutProviderTheClientIsThe124One(): void
    {
        $container = $this->boot('none', ['dsn' => F::CONFIG_DSN]);
        self::assertSame(0, $this->lint());
        self::assertInstanceOf(BeaconClient::class, $container->get(BeaconClientInterface::class));
        self::assertFalse($container->has('app.beacon_credentials') && $container->get(BeaconCredentialsResolver::class) instanceof stdClass);

        $this->request($container, 0);
        self::assertSame(['pub-env'], $this->ingest($container)->publicKeys());
    }

    public function testAProviderThatIsNotAPortFailsAtCompileTime(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement Nowo\BeaconBundle\Credentials\BeaconCredentialsProviderInterface');
        $this->boot('none', ['credentials_provider' => ScriptedIngest::class]);
    }

    public function testAMissingProviderServiceFailsAtCompileTime(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('service "app.missing" does not exist');
        $this->boot('none', ['credentials_provider' => 'app.missing']);
    }

    public function testWorkerRotatesTheDsnAndAlternatesProjectsWithoutResetter(): void
    {
        $container = $this->boot('ddd');
        $store     = $this->store($container);
        $ingest    = $this->ingest($container);
        $expires   = new DateTimeImmutable('+1 day');
        $baseline  = 0;

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $projectTwo = intdiv($i, 50) % 2 === 1;
            $rotated    = $i >= 600;
            // For 25 requests after the rotation Beacon does not know the new secret yet: one 401, then the previous DSN.
            $lagging = $rotated && $i < 625;

            $ingest->acceptedSecrets = $lagging ? ['fixture-secret-db', 'fixture-secret-two'] : ['fixture-secret-db', 'fixture-secret-next', 'fixture-secret-two'];
            $store->record           = match (true) {
                $projectTwo => new BeaconCredentials(F::OTHER_PROJECT_DSN),
                $rotated    => new BeaconCredentials(F::NEXT_DSN, null, F::DB_DSN, $expires),
                default     => new BeaconCredentials(F::DB_DSN),
            };

            $ingest->calls = [];
            $this->request($container, $i);

            $expected = match (true) {
                $projectTwo => ['pub-two'],
                $lagging    => ['pub-next', 'pub-db'],
                $rotated    => ['pub-next'],
                default     => ['pub-db'],
            };
            self::assertSame($expected, $ingest->publicKeys(), 'iteration ' . $i);
            self::assertStringContainsString($projectTwo ? '/v1/2/envelope/' : '/v1/1/envelope/', $ingest->firstUrl());

            if ($i === 50) {
                $baseline = $this->memory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->memory() - $baseline, 'memory stays flat');
    }

    /**
     * @param 'ddd'|'none'|'standard' $host
     * @param array<string, mixed> $beacon
     */
    private function boot(string $host, array $beacon = []): ContainerInterface
    {
        (new Filesystem())->remove(CredentialsKernel::baseDir());
        $this->kernel = new CredentialsKernel($host, $beacon);
        $this->kernel->boot();
        $this->container = $this->kernel->getContainer();

        return $this->container;
    }

    private function lint(): int
    {
        self::assertNotNull($this->kernel);
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        return $application->run(new ArrayInput(['command' => 'lint:container']), new BufferedOutput());
    }

    private function request(ContainerInterface $container, int $i): void
    {
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $request  = Request::create('/report/' . $i);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }

    private function store(ContainerInterface $container): InMemoryCredentialsStore
    {
        $store = $container->get('app.beacon_credentials');
        self::assertInstanceOf(InMemoryCredentialsStore::class, $store);

        return $store;
    }

    private function ingest(ContainerInterface $container): ScriptedIngest
    {
        $ingest = $container->get(ScriptedIngest::class);
        self::assertInstanceOf(ScriptedIngest::class, $ingest);

        return $ingest;
    }

    private function memory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
