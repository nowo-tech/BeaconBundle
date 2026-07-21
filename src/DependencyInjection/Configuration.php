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

    /**
     * Builds the `nowo_beacon` configuration tree.
     */
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
                    ->info('Beacon DSN: https://PUBLIC_KEY:SECRET_KEY@host:port/PROJECT_ID (empty disables sending). Prefer %env(default::BEACON_DSN)%.')
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
                ->booleanNode('register_console_listener')
                    ->info('When true, report uncaught console command errors (ConsoleEvents::ERROR).')
                    ->defaultTrue()
                ->end()
                ->booleanNode('register_messenger_listener')
                    ->info('When true and symfony/messenger is installed, report WorkerMessageFailedEvent failures that will not retry.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('auto_http_transaction')
                    ->info('When true, send a performance transaction for each main HTTP request (skips profiler/health/build).')
                    ->defaultFalse()
                ->end()
                ->arrayNode('monolog_handler')
                    ->info('Optional Monolog handler (requires monolog/monolog). Disabled by default.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('level')
                            ->info('Minimum Monolog level to forward (e.g. error, warning).')
                            ->defaultValue('error')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('send')
                    ->info('Opt-in/out switches for categories of context attached to outbound events.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('environment')
                            ->info('Send environment tag.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('release')
                            ->info('Send release / code version when configured.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('server_name')
                            ->info('Send server hostname tag.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('stacktrace')
                            ->info('Send stack frames and culprit for exceptions, and current stack for captureMessage().')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('request')
                            ->info('Attach current HTTP request (url/method/query) to events and transactions when a request is available.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('user')
                            ->info('Send authenticated user summary (may include PII). Disabled by default.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('runtime')
                            ->info('Send PHP runtime version in contexts.runtime.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('framework')
                            ->info('Send Symfony version in contexts.framework when available.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('os')
                            ->info('Send OS family/version in contexts.os.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
