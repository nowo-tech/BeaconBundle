<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Golden;

use Closure;
use Error;
use Exception;
use LogicException;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Tests\Fixtures\Golden\Legacy\LegacyEnvelopeBuilder;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PDOException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

use function array_keys;
use function explode;
use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function preg_replace;
use function rtrim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Deterministic inputs for the golden envelopes of {@see EnvelopeBuilder}.
 *
 * The first thirteen cases are beacon-php's (`tests/Fixtures/Golden/GoldenCases.php`, recorded from BeaconBundle
 * 1.19.1 in `bundle-1.19/`); the `*_1192` cases exercise the redaction rules added in 1.19.2 (recorded from 1.20.0 in
 * `bundle-1.20/`). Output is normalised (event id → `bbbb…`, breadcrumb timestamps → 0.5) and compared byte for byte.
 *
 * Inputs avoid environment-dependent data (PHP version, OS release, absolute paths of real files).
 */
final class GoldenCases
{
    public const DSN = 'https://pubkey:secret@beacon.example.com:9447/1';

    public const EVENT_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const TRACE_ID = 'trace-golden-0001';

    public const CLOCK = '2024-07-20T16:01:00.123456Z';

    /** Cases recorded from BeaconBundle 1.19.1 (shared with beacon-php). */
    public const BUNDLE_119 = [
        'event_message',
        'event_exception',
        'event_exception_no_stacktrace',
        'event_database',
        'event_request',
        'event_request_client',
        'event_user_breadcrumbs',
        'transaction',
        'deploy',
        'deploy_defaults',
        'queue_snapshot',
        'schedule_catalog',
        'check_in',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Output of the current {@see EnvelopeBuilder} for one case (normalised).
     */
    public static function current(string $name): string
    {
        return self::normalize(self::run(self::definitions()[$name], EnvelopeBuilder::class));
    }

    /**
     * Output of the frozen 1.20.0 builder for one case (normalised).
     */
    public static function legacy(string $name): string
    {
        return self::normalize(self::run(self::definitions()[$name], LegacyEnvelopeBuilder::class));
    }

    /**
     * Replace the random event id and the breadcrumb timestamps (microtime) by constants.
     */
    public static function normalize(string $body): string
    {
        $lines = explode("\n", rtrim($body, "\n"));
        foreach ($lines as $index => $line) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (isset($decoded['breadcrumbs']) && is_array($decoded['breadcrumbs']) && isset($decoded['breadcrumbs']['values']) && is_array($decoded['breadcrumbs']['values'])) {
                foreach ($decoded['breadcrumbs']['values'] as $i => $crumb) {
                    if (is_array($crumb)) {
                        $crumb['timestamp']                   = 0.5;
                        $decoded['breadcrumbs']['values'][$i] = $crumb;
                    }
                }
                $line = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $lines[$index] = (string) preg_replace('/"event_id":"[0-9a-f]{32}"/', '"event_id":"' . self::EVENT_ID . '"', $line);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The same envelope without the header `dsn` (BeaconBundle 1.21 no longer sends it; the secret travels only in
     * `X-Beacon-Auth`). Every other byte is unchanged.
     */
    public static function withoutHeaderDsn(string $body): string
    {
        return (string) preg_replace('/^(\{"event_id":"[0-9a-f]{32}"),"dsn":"[^"]*"/', '$1', $body, 1);
    }

    /**
     * @param array<string, mixed> $case
     * @param class-string<EnvelopeBuilder|LegacyEnvelopeBuilder> $builderClass
     */
    private static function run(array $case, string $builderClass): string
    {
        $scope = new Scope();
        /** @var array<string, string> $tags */
        $tags = $case['tags'] ?? [];
        $scope->setTags($tags);
        $crumbs = new BreadcrumbBuffer();
        /** @var list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}> $breadcrumbs */
        $breadcrumbs = $case['breadcrumbs'] ?? [];
        foreach ($breadcrumbs as $crumb) {
            $crumbs->add($crumb[0], $crumb[1], $crumb[2], $crumb[3]);
        }
        $trace = new TraceIdProvider();
        $trace->set(self::TRACE_ID);

        $stack = null;
        if (isset($case['request']) && is_array($case['request'])) {
            /** @var array<string, mixed> $spec */
            $spec  = $case['request'];
            $stack = new RequestStack([self::symfonyRequest($spec)]);
        }

        $user = null;
        if (isset($case['user']) && is_array($case['user'])) {
            /** @var array<string, string> $userData */
            $userData = $case['user'];
            $user     = new class($userData) implements UserContextProviderInterface {
                /**
                 * @param array<string, string> $user
                 */
                public function __construct(private readonly array $user)
                {
                }

                public function getUserContext(): array
                {
                    return $this->user;
                }
            };
        }

        /** @var array{environment?: bool, release?: bool, server_name?: bool, stacktrace?: bool, request?: bool, user?: bool, runtime?: bool, framework?: bool, os?: bool, client?: bool, stack_vars?: bool} $send */
        $send = $case['send'];

        $builder = new $builderClass(
            environment: 'test',
            release: '1.2.3',
            serverName: 'ci-host',
            sendOptions: SendOptions::fromArray($send),
            userContextProvider: $user,
            breadcrumbBuffer: $crumbs,
            requestStack: $stack,
            scope: $scope,
            traceIdProvider: $trace,
            clock: new MockClock(self::CLOCK),
        );

        /** @var Closure(EnvelopeBuilder|LegacyEnvelopeBuilder, BeaconDsn): string $build */
        $build = $case['build'];

        return $build($builder, (new BeaconDsnParser())->parse(self::DSN));
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function symfonyRequest(array $spec): Request
    {
        /** @var array<string, string> $server */
        $server  = $spec['server'] ?? [];
        $request = Request::create((string) $spec['uri'], (string) ($spec['method'] ?? 'GET'), [], [], [], $server);
        /** @var array<string, string> $attributes */
        $attributes = $spec['attributes'] ?? [];
        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }

        return $request;
    }

    /**
     * Exception chain with fixed files, lines and traces (no real file: no source context, stable everywhere).
     */
    public static function checkoutException(): RuntimeException
    {
        $inner = self::shape(new LogicException('card gateway said api_key=sk_live_0123456789abcdef'), '/srv/app/src/Gateway/Card.php', 77, [
            ['file' => '/srv/app/src/Checkout.php', 'line' => 40, 'function' => 'charge', 'class' => 'App\\Gateway\\Card', 'type' => '->'],
        ]);

        return self::shape(new RuntimeException('payment declined', 0, $inner), '/srv/app/src/Checkout.php', 42, [
            ['file' => '/srv/app/src/Controller/PayController.php', 'line' => 18, 'function' => 'pay', 'class' => 'App\\Checkout', 'type' => '->'],
            ['file' => '/srv/app/vendor/symfony/http-kernel/HttpKernel.php', 'line' => 181, 'function' => 'handleRaw', 'class' => 'Symfony\\Component\\HttpKernel\\HttpKernel', 'type' => '->'],
            ['function' => '{closure}'],
            ['file' => '/srv/app/public/index.php', 'line' => 9, 'function' => 'require_once'],
        ]);
    }

    public static function databaseException(): PDOException
    {
        $exception            = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane@example.com' for key 'email' (Connection: mysql, SQL: insert into users (email) values ('jane@example.com'))");
        $exception->errorInfo = ['23000', 1062, "Duplicate entry 'jane@example.com'"];

        return self::shape($exception, '/srv/app/src/Repository/UserRepository.php', 12, []);
    }

    /**
     * Chain whose messages and frame arguments only the 1.19.2 redaction rules catch (env-style and camelCase names,
     * URL passwords with `/` or `@`, worker keys, cards; `session*` / `*Pin` / `signature` / `iban` argument names).
     */
    public static function redactionException(): RuntimeException
    {
        $inner = self::shape(new LogicException('AWS_SECRET_ACCESS_KEY=placeholder apiToken=tok_123456 card 4111 1111 1111 1111'), '/srv/app/src/Mailer.php', 31, [
            [
                'file'     => '/srv/app/src/Security/Login.php',
                'line'     => 55,
                'function' => 'login',
                'class'    => FrameVarsTarget::class,
                'type'     => '->',
                'args'     => ['sess-0123456789', '1234', ['signature' => 'sig-abc', 'iban' => 'DE89370400440532013000', 'shipping' => 'kept', 'dbPass' => 'p4ss']],
            ],
        ]);

        return self::shape(new RuntimeException('smtp://mailer:p@ss/w0rd@mail.example refused; DB_PASSWORD=hunter2', 0, $inner), '/srv/app/src/Notifier.php', 12, [
            ['file' => '/srv/app/src/Controller/NotifyController.php', 'line' => 20, 'function' => 'notify', 'class' => 'App\\Notifier', 'type' => '->', 'args' => ['plain']],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @template T of Throwable
     *
     * @param T $throwable
     *
     * @return T
     */
    private static function shape(Throwable $throwable, string $file, int $line, array $trace): Throwable
    {
        $class = $throwable instanceof Exception ? Exception::class : Error::class;
        (new ReflectionProperty($class, 'file'))->setValue($throwable, $file);
        (new ReflectionProperty($class, 'line'))->setValue($throwable, $line);
        (new ReflectionProperty($class, 'trace'))->setValue($throwable, $trace);

        return $throwable;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(): array
    {
        $offline = ['runtime' => false, 'os' => false, 'framework' => false];

        return [
            'event_message' => [
                'send'  => $offline + ['stacktrace' => false],
                'tags'  => ['area' => 'billing', 'tenant' => 'acme'],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'checkout failed password=hunter2 Bearer abcdefgh12345678', 'warning', null, ['order' => 42, 'nested' => ['a' => [1, 2], 'empty' => []]], ['checkout', 'failed']),
            ],
            'event_exception' => [
                'send'  => $offline,
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, '', 'error', self::checkoutException(), ['handled' => false]),
            ],
            'event_exception_no_stacktrace' => [
                'send'  => $offline + ['stacktrace' => false, 'environment' => false, 'release' => false, 'server_name' => false],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'wrapped: payment declined', 'error', self::checkoutException()),
            ],
            'event_database' => [
                'send'  => $offline,
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'db failure', 'error', self::databaseException()),
            ],
            'event_request' => [
                'send'    => $offline + ['stacktrace' => false],
                'request' => [
                    'uri'    => 'https://user:pass@shop.example/checkout/pay?token=abc123&page=2&api_key=k3y&q=caf%C3%A9',
                    'method' => 'POST',
                    'server' => [
                        'HTTP_USER_AGENT'      => 'Mozilla/5.0 Golden',
                        'HTTP_ACCEPT'          => 'text/html',
                        'HTTP_REFERER'         => 'https://shop.example/login?password=hunter2&next=%2Fhome',
                        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
                        'HTTP_COOKIE'          => 'session=secret',
                        'HTTP_AUTHORIZATION'   => 'Bearer abcdefgh12345678',
                        'REMOTE_ADDR'          => '198.51.100.7',
                    ],
                    'attributes' => ['_route' => 'checkout_pay', '_controller' => 'App\\Controller\\PayController::__invoke'],
                ],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'payment failed', 'error', null, ['request_uri' => 'kept-by-caller']),
            ],
            'event_request_client' => [
                'send'    => $offline + ['stacktrace' => false, 'client' => true],
                'request' => [
                    'uri'    => 'https://shop.example/health',
                    'server' => ['HTTP_USER_AGENT' => 'curl/8.0', 'REMOTE_ADDR' => '198.51.100.7'],
                ],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'health degraded', 'info'),
            ],
            'event_user_breadcrumbs' => [
                'send'        => $offline + ['stacktrace' => false, 'user' => true],
                'user'        => ['id' => '42', 'username' => 'jane', 'email' => 'jane@example.com'],
                'breadcrumbs' => [
                    ['cart loaded', 'app', 'info', ['items' => 3]],
                    ['GET https://api.example/v1', 'http', 'debug', ['status' => 200, 'url' => 'https://api.example/v1?token=x']],
                ],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'checkout ünïcödé ✓', 'error', null, [], ['ü']),
            ],
            'transaction' => [
                'send'        => $offline,
                'tags'        => ['route' => 'checkout'],
                'breadcrumbs' => [['sql', 'db', 'info', []]],
                'request'     => ['uri' => 'https://shop.example/checkout', 'method' => 'GET'],
                'build'       => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildTransactionEnvelope($dsn, 'GET /checkout', 1721491260.25, 1721491260.75, [
                    ['op' => 'db.sql.query', 'description' => 'SELECT 1', 'span_id' => 'aaaaaaaaaaaaaaaa', 'start_timestamp' => 1721491260.3, 'timestamp' => 1721491260.4],
                ], ['status_code' => 200]),
            ],
            'deploy' => [
                'send'  => $offline,
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildDeployEnvelope($dsn, ['release' => '1.2.3', 'commit' => 'abc123', 'url' => 'https://ci.example/run/1', 'name' => '', 'deployed_at' => 1721491200.5]),
            ],
            'deploy_defaults' => [
                'send'  => $offline + ['environment' => false],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildDeployEnvelope($dsn, ['release' => '1.2.4']),
            ],
            'queue_snapshot' => [
                'send'  => $offline,
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildQueueSnapshotEnvelope($dsn, [['name' => 'async', 'count' => 3, 'failure' => false], ['name' => 'failed', 'count' => null, 'failure' => true]]),
            ],
            'schedule_catalog' => [
                'send'  => $offline,
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildScheduleCatalogEnvelope($dsn, [['name' => 'default', 'entries' => [['recurring_id' => 'r1', 'trigger' => 'every 5 minutes', 'description' => null]]]]),
            ],
            'check_in' => [
                'send'  => $offline + ['server_name' => false],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildCheckInEnvelope($dsn, ['run_id' => 'run-1', 'schedule' => 'default', 'recurring_id' => 'r1', 'status' => 'error', 'triggered_at' => 1721491260.0, 'error' => 'RuntimeException']),
            ],
            'event_message_1192' => [
                'send'  => $offline + ['stacktrace' => false],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'boot failed DB_PASSWORD=placeholder dbPassword: placeholder "client_secret": "two words" \'pass\' => \'x1\' redis://:placeholder@cache:6379 worker bwk_abcdefghijklmnopqrstuv card 4111 1111 1111 1111 passenger=kept', 'error'),
            ],
            'event_exception_1192' => [
                'send'  => $offline + ['stack_vars' => true],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, '', 'error', self::redactionException()),
            ],
            'event_exception_message_1192' => [
                'send'  => $offline + ['stacktrace' => false],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'notify failed clientSecret=abc123def', 'error', self::redactionException(), ['request_uri' => 'kept']),
            ],
            'event_request_1192' => [
                'send'    => $offline + ['stacktrace' => false],
                'request' => [
                    'uri'    => 'https://shop.example/reset?sid=abc&reset=tok123&key=k1&page=2&dbPassword=x&pwd=y',
                    'method' => 'GET',
                    'server' => [
                        'HTTP_REFERER' => 'https://:refpw@shop.example/back?_hash=abc&X-Amz-Signature=zzz&code=c0de&sort=asc',
                    ],
                ],
                'build' => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildEventEnvelope($dsn, 'reset failed', 'error'),
            ],
            'transaction_request_1192' => [
                'send'    => $offline,
                'request' => ['uri' => 'https://shop.example/api?apiToken=t0k&sid=s1', 'method' => 'GET'],
                'build'   => static fn (EnvelopeBuilder|LegacyEnvelopeBuilder $b, BeaconDsn $dsn): string => $b->buildTransactionEnvelope($dsn, 'GET /api', 1721491260.25, 1721491260.75),
            ],
        ];
    }
}
