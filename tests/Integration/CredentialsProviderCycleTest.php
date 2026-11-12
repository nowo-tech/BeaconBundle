<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\ScriptedIngest;
use Nowo\BeaconBundle\Tests\Fixtures\CycleKernel\CycleKernel;
use Nowo\BeaconBundle\Tests\Fixtures\CycleKernel\LoggingInstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * 1.25.2: with the InstanceSettings bridge and the Beacon Monolog handler, the container builds (no instantiation
 * cycle), `cache:warmup` / `cache:clear` succeed, and a log record written while InstanceSettings is read reaches
 * Beacon with the configured DSN instead of recursing into the provider.
 */
#[CoversNothing]
final class CredentialsProviderCycleTest extends TestCase
{
    private ?CycleKernel $kernel = null;

    protected function tearDown(): void
    {
        $container = $this->kernel?->getContainer();
        foreach ([BeaconFatalErrorHandler::class, BeaconPhpWarningHandler::class] as $id) {
            $handler = $container?->initialized($id) === true ? $container->get($id) : null;
            if ($handler instanceof BeaconFatalErrorHandler || $handler instanceof BeaconPhpWarningHandler) {
                $handler->unregister();
            }
        }

        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(CycleKernel::baseDir());
    }

    public function testBridgeAndMonologHandlerBuildWarmUpAndNeverRecurse(): void
    {
        (new Filesystem())->remove(CycleKernel::baseDir());
        $this->kernel = new CycleKernel();
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        // Entry through InstanceSettings, as a Doctrine / settings consumer would: InstanceSettings → logger →
        // pushHandler(Beacon handler) → client → resolver → store → InstanceSettings (not built yet) recursed in 1.25.1.
        $settings = $container->get(InstanceSettingsInterface::class);
        self::assertInstanceOf(LoggingInstanceSettings::class, $settings);

        foreach ([['command' => 'cache:warmup'], ['command' => 'cache:clear', '--no-warmup' => true]] as $input) {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);
            $output = new BufferedOutput();
            self::assertSame(0, $application->run(new ArrayInput($input), $output), $output->fetch());
        }

        $settings->save('beacon', ['dsn' => F::DB_DSN]);
        $ingest = $container->get(ScriptedIngest::class);
        self::assertInstanceOf(ScriptedIngest::class, $ingest);

        $this->request($container, 1);

        // The settings read logged an error: the handler reported it with the configured DSN (nested read), then the
        // request's own event went out with the stored DSN.
        self::assertSame(['pub-env', 'pub-db'], $ingest->publicKeys());
        self::assertSame(1, $settings->reads);
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
}
