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
        self::assertTrue($config['register_error_listener']);
        self::assertTrue($config['register_console_listener']);
        self::assertTrue($config['register_messenger_listener']);
        self::assertFalse($config['auto_http_transaction']);
        self::assertFalse($config['monolog_handler']['enabled']);
        self::assertSame('error', $config['monolog_handler']['level']);
        self::assertSame([], $config['ignore_exceptions']);
        self::assertTrue($config['send']['environment']);
        self::assertTrue($config['send']['release']);
        self::assertTrue($config['send']['server_name']);
        self::assertTrue($config['send']['stacktrace']);
        self::assertTrue($config['send']['request']);
        self::assertFalse($config['send']['user']);
        self::assertTrue($config['send']['runtime']);
        self::assertTrue($config['send']['framework']);
        self::assertTrue($config['send']['os']);
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
            'ignore_exceptions'       => [
                RuntimeException::class,
                InvalidArgumentException::class,
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
        self::assertSame([
            RuntimeException::class,
            InvalidArgumentException::class,
        ], $config['ignore_exceptions']);
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
}
