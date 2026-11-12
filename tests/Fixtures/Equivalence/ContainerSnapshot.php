<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Equivalence;

use Nowo\BeaconBundle\DependencyInjection\NowoBeaconExtension;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_scalar;
use function ksort;

/**
 * Deterministic description of what NowoBeaconExtension::load() registers (definitions with class, factory, arguments,
 * tags, public flag; aliases; parameters), used to prove that a configuration without credentials provider wires
 * exactly what 1.24.1 wired. The golden file was generated with the 1.24.1 sources and this same class.
 */
final class ContainerSnapshot
{
    /** Configurations compared with 1.24.1. */
    public const CONFIGS = [
        'literal_dsn' => [[
            'dsn'         => 'https://pubkey:fixture-secret@beacon.example.invalid:9444/5',
            'environment' => 'prod',
            'server_name' => 'host-1',
        ]],
        'env_dsn_messenger_remote_retry' => [[
            'dsn'         => '%env(default::BEACON_DSN)%',
            'server_name' => 'host-1',
            'transport'   => ['mode' => 'messenger'],
            'messenger'   => ['remote_retry' => ['enabled' => true, 'worker_key' => '%env(default::BEACON_WORKER_KEY)%']],
        ]],
        'async_self_test_http' => [[
            'dsn'         => 'https://pubkey:fixture-secret@beacon.example.invalid/5',
            'server_name' => 'host-1',
            'transport'   => ['mode' => 'async'],
            'self_test'   => ['http' => ['enabled' => true]],
        ]],
        'empty_dsn' => [[
            'dsn'         => '',
            'server_name' => 'host-1',
        ]],
    ];

    /**
     * Services, arguments and parameters that 1.25 adds for every configuration (inert without a provider).
     */
    public const ADDED_IN_1_25 = [
        'services'   => ['Nowo\\BeaconBundle\\Credentials\\BeaconCredentialsResolver', 'Nowo\\BeaconBundle\\Command\\RotateCredentialsCommand'],
        'arguments'  => ['$resolver', '$credentials'],
        'parameters' => ['nowo.beacon.credentials_provider'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function take(string $name): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoBeaconExtension())->load(self::CONFIGS[$name], $container);

        $services = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (in_array($id, self::ADDED_IN_1_25['services'], true) || $id === 'service_container') {
                continue;
            }

            $services[$id] = self::definition($definition);
        }

        $aliases = [];
        foreach ($container->getAliases() as $id => $alias) {
            $aliases[$id] = self::alias($alias);
        }

        $parameters = $container->getParameterBag()->all();
        foreach (self::ADDED_IN_1_25['parameters'] as $parameter) {
            unset($parameters[$parameter]);
        }

        // gethostname() differs between machines.
        unset($parameters['nowo.beacon.server_name']);
        ksort($services);
        ksort($aliases);
        ksort($parameters);

        return ['services' => $services, 'aliases' => $aliases, 'parameters' => self::value($parameters)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(Definition $definition): array
    {
        $arguments = [];
        foreach ($definition->getArguments() as $key => $value) {
            if ($value === null && in_array($key, self::ADDED_IN_1_25['arguments'], true)) {
                continue;
            }

            $arguments[$key] = self::value($value);
        }

        $tags = $definition->getTags();
        ksort($tags);

        return [
            'class'     => $definition->getClass(),
            'factory'   => self::value($definition->getFactory()),
            'arguments' => $arguments,
            'calls'     => self::value($definition->getMethodCalls()),
            'tags'      => self::value($tags),
            'public'    => $definition->isPublic(),
            'decorates' => self::value($definition->getDecoratedService()),
            'autowired' => $definition->isAutowired(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function alias(Alias $alias): array
    {
        return ['id' => (string) $alias, 'public' => $alias->isPublic()];
    }

    private static function value(mixed $value): mixed
    {
        if ($value instanceof Reference) {
            return '@' . $value . '#' . $value->getInvalidBehavior();
        }

        if ($value instanceof Definition) {
            return ['inline' => self::definition($value)];
        }

        if ($value instanceof IteratorArgument) {
            return ['iterator' => self::value($value->getValues())];
        }

        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return get_debug_type($value);
    }
}
