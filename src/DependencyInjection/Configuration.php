<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the configuration tree for `nowo_beacon`.
 *
 * Point the DSN at any Beacon host (domain, subdomain, and optional port)
 * using `BEACON_DSN` (empty disables reporting).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_beacon';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch. When false, no events are sent even if DSN is set.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('dsn')
                    ->info('Beacon DSN: https://PUBLIC_KEY@host:port/PROJECT_ID (empty disables sending). Prefer %env(default::BEACON_DSN)%.')
                    ->defaultValue('')
                ->end()
                ->scalarNode('environment')
                    ->info('Environment tag sent with events (e.g. prod, staging).')
                    ->defaultValue('%kernel.environment%')
                ->end()
                ->scalarNode('release')
                    ->info('Optional release / version string attached to events.')
                    ->defaultNull()
                ->end()
                ->scalarNode('server_name')
                    ->info('Hostname tag for events. Defaults to gethostname() when null.')
                    ->defaultNull()
                ->end()
                ->booleanNode('verify_peer')
                    ->info('TLS certificate verification. Set false for local self-signed Beacon HTTPS (dev only).')
                    ->defaultTrue()
                ->end()
                ->floatNode('timeout')
                    ->info('HTTP timeout in seconds for ingest requests.')
                    ->defaultValue(5.0)
                    ->min(0.1)
                ->end()
                ->booleanNode('register_error_listener')
                    ->info('When true, register a kernel.exception listener that reports uncaught exceptions.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('ignore_exceptions')
                    ->info('FQCN list of exception classes that must not be reported by the error listener.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
