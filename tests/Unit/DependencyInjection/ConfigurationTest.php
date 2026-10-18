<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\BeaconBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame('', $config['dsn']);
        self::assertSame('%kernel.environment%', $config['environment']);
        self::assertNull($config['release']);
        self::assertNull($config['server_name']);
        self::assertTrue($config['verify_peer']);
        self::assertSame(5.0, $config['timeout']);
        self::assertSame('sync', $config['transport']['mode']);
        self::assertTrue($config['register_error_listener']);
        self::assertTrue($config['register_console_listener']);
        self::assertTrue($config['register_messenger_listener']);
        self::assertTrue($config['include_scheduler_context']);
        self::assertFalse($config['auto_http_transaction']);
        self::assertNull($config['before_send']);
        self::assertFalse($config['instrumentation']['doctrine']);
        self::assertFalse($config['instrumentation']['http_client']);
        self::assertFalse($config['monolog_handler']['enabled']);
        self::assertSame('error', $config['monolog_handler']['level']);
        self::assertSame([], $config['ignore_exceptions']);
        self::assertSame([
            '/_profiler',
            '/_wdt',
            '/build',
            '/assets',
            '/health',
            '/.well-known/appspecific/com.chrome.devtools.json',
        ], $config['ignore_paths']);
        self::assertTrue($config['send']['environment']);
        self::assertTrue($config['send']['release']);
        self::assertTrue($config['send']['server_name']);
        self::assertTrue($config['send']['stacktrace']);
        self::assertTrue($config['send']['request']);
        self::assertFalse($config['send']['user']);
        self::assertTrue($config['send']['runtime']);
        self::assertTrue($config['send']['framework']);
        self::assertTrue($config['send']['os']);
        self::assertFalse($config['send']['stack_vars']);
    }

    public function testCustomConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'enabled'                 => false,
            'dsn'                     => 'https://k:s@host:9444/1',
            'environment'             => 'staging',
            'release'                 => '2026.07.20',
            'server_name'             => 'app-01',
            'verify_peer'             => false,
            'timeout'                 => 0.5,
            'register_error_listener' => false,
            'auto_http_transaction'   => true,
            'before_send'             => 'App\\Beacon\\ScrubPii',
            'instrumentation'         => [
                'doctrine'    => true,
                'http_client' => true,
            ],
            'ignore_exceptions' => [
                RuntimeException::class,
                InvalidArgumentException::class,
            ],
            'ignore_paths' => [
                '/health',
                '/custom-noise',
            ],
            'send' => [
                'user'       => true,
                'stacktrace' => false,
                'request'    => false,
            ],
        ]]);

        self::assertFalse($config['enabled']);
        self::assertSame('https://k:s@host:9444/1', $config['dsn']);
        self::assertSame('staging', $config['environment']);
        self::assertSame('2026.07.20', $config['release']);
        self::assertSame('app-01', $config['server_name']);
        self::assertFalse($config['verify_peer']);
        self::assertSame(0.5, $config['timeout']);
        self::assertFalse($config['register_error_listener']);
        self::assertTrue($config['auto_http_transaction']);
        self::assertSame('App\\Beacon\\ScrubPii', $config['before_send']);
        self::assertTrue($config['instrumentation']['doctrine']);
        self::assertTrue($config['instrumentation']['http_client']);
        self::assertSame([
            RuntimeException::class,
            InvalidArgumentException::class,
        ], $config['ignore_exceptions']);
        self::assertSame(['/health', '/custom-noise'], $config['ignore_paths']);
        self::assertTrue($config['send']['user']);
        self::assertFalse($config['send']['stacktrace']);
        self::assertFalse($config['send']['request']);
        self::assertTrue($config['send']['runtime']);
    }

    public function testTimeoutMustBeAtLeastMinimum(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'timeout' => 0.05,
        ]]);
    }

    public function testSelfTestHttpDefaultsAndPathValidation(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame(['enabled' => false, 'path' => '/_beacon/self-test', 'roles' => ['ROLE_ADMIN']], $config['self_test']['http']);

        $custom = (new Processor())->processConfiguration(new Configuration(), [[
            'self_test' => ['http' => ['enabled' => true, 'path' => '/admin/beacon', 'roles' => []]],
        ]]);
        self::assertSame(['enabled' => true, 'path' => '/admin/beacon', 'roles' => []], $custom['self_test']['http']);

        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [[
            'self_test' => ['http' => ['path' => 'admin/beacon']],
        ]]);
    }

    public function testSchedulerCheckInsDefaultsAndValidation(): void
    {
        $defaults = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame(
            ['enabled' => false, 'max_runtime' => [], 'ignore' => [], 'descriptions' => [], 'catalog_refresh_hours' => 24],
            $defaults['scheduler']['check_ins'],
        );

        $custom = (new Processor())->processConfiguration(new Configuration(), [[
            'scheduler' => ['check_ins' => [
                'enabled'               => true,
                'max_runtime'           => ['app:reports-nightly' => 1800, 'App\\Message\\SyncCatalog' => 600],
                'ignore'                => ['app:noisy'],
                'descriptions'          => ['app:reports-nightly' => 'Nightly report.'],
                'catalog_refresh_hours' => 6,
            ]],
        ]]);
        self::assertSame(['app:reports-nightly' => 'Nightly report.'], $custom['scheduler']['check_ins']['descriptions']);
        self::assertSame(
            ['app:reports-nightly' => 1800, 'App\\Message\\SyncCatalog' => 600],
            $custom['scheduler']['check_ins']['max_runtime'],
            'keys are not normalized (dashes in command names kept)',
        );
        self::assertSame(6, $custom['scheduler']['check_ins']['catalog_refresh_hours']);

        foreach ([
            ['max_runtime' => ['app:x' => 0]],
            ['max_runtime' => ['app:x' => 604801]],
            ['catalog_refresh_hours' => 0],
            ['catalog_refresh_hours' => 169],
        ] as $invalid) {
            try {
                (new Processor())->processConfiguration(new Configuration(), [['scheduler' => ['check_ins' => $invalid]]]);
                self::fail('Expected invalid configuration for ' . json_encode($invalid));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMessengerRemoteRetryDefaultsAndValidation(): void
    {
        $defaults = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame([
            'enabled'            => false,
            'worker_key'         => '%env(default::BEACON_WORKER_KEY)%',
            'allowed_actions'    => ['retry', 'discard'],
            'failure_transports' => [],
            'poll_interval'      => 30,
            'poll_in_workers'    => true,
        ], $defaults['messenger']['remote_retry']);

        $custom = (new Processor())->processConfiguration(new Configuration(), [[
            'messenger' => ['remote_retry' => [
                'enabled'            => true,
                'worker_key'         => '%env(BEACON_WORKER_KEY)%',
                'allowed_actions'    => ['retry'],
                'failure_transports' => ['failed', 'failed_high'],
                'poll_interval'      => 600,
                'poll_in_workers'    => false,
            ]],
        ]]);
        self::assertSame(['retry'], $custom['messenger']['remote_retry']['allowed_actions']);
        self::assertSame(['failed', 'failed_high'], $custom['messenger']['remote_retry']['failure_transports']);

        foreach ([
            ['poll_interval' => 9],
            ['poll_interval' => 601],
            ['allowed_actions' => ['purge']],
            ['enabled' => true, 'worker_key' => ''],
            ['enabled' => true, 'worker_key' => null],
        ] as $invalid) {
            try {
                (new Processor())->processConfiguration(new Configuration(), [['messenger' => ['remote_retry' => $invalid]]]);
                self::fail('Expected invalid configuration for ' . json_encode($invalid));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
