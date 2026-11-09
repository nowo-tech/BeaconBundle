<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\Beacon\Envelope\Envelope;
use Nowo\Beacon\Envelope\EnvelopeBuilder as CoreEnvelopeBuilder;
use Nowo\Beacon\Envelope\EnvelopeDsnMode;
use Nowo\Beacon\Envelope\SendOptions as CoreSendOptions;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Client\BeaconClient;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Messenger\MessengerContext;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Support\FrameVarsSerializer;
use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_reverse;
use function array_values;
use function class_exists;
use function count;
use function debug_backtrace;
use function dirname;
use function is_array;
use function is_string;
use function json_encode;
use function json_last_error_msg;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Builds Envelope NDJSON payloads for Beacon ingest.
 *
 * The envelope is built by beacon-php's {@see CoreEnvelopeBuilder} (same keys, order and values as BeaconBundle 1.20,
 * locked by the golden tests in `tests/Contract/EnvelopeGoldenBytesTest.php`) with the Symfony specifics injected:
 * the current request ({@see RequestStack}), the framework context (`symfony` + {@see Kernel::VERSION}), Messenger's
 * {@see HandlerFailedException} root cause for the culprit, and the bundle's classes / `src/` hidden from message
 * stack traces.
 *
 * Two bundle rules are kept on top of the core output:
 *
 * - **Redaction.** The texts the builder redacts (message, exception values, request URL / query string / referer,
 *   frame `vars`) are redacted from the raw input with the bundle's {@see SensitiveValueRedactor} /
 *   {@see FrameVarsSerializer} (the 1.19.2 rules: env-style and camelCase names, quoted JSON, URL passwords with `/`
 *   or `@`, worker keys, cards, …), not with beacon-php 0.1's older rules.
 * - **Encoding.** Lines are encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; invalid UTF-8 still
 *   throws a {@see RuntimeException}.
 *
 * The envelope header carries `event_id` and `sent_at` only: since 1.21 the DSN (and its secret) is no longer sent in
 * the body; the secret travels only in the `X-Beacon-Auth` header ({@see EnvelopeTransport}).
 */
final class EnvelopeBuilder
{
    private readonly CoreEnvelopeBuilder $core;

    private readonly ?SymfonyRequestContextProvider $requestProvider;

    /** @var list<string> path prefixes of implementation frames (beacon-php src/, bundle src/) */
    private readonly array $implementationPaths;

    public function __construct(
        string $environment = 'prod',
        ?string $release = null,
        string $serverName = 'unknown',
        private readonly SendOptions $sendOptions = new SendOptions(),
        ?UserContextProviderInterface $userContextProvider = null,
        ?BreadcrumbBuffer $breadcrumbBuffer = null,
        ?RequestStack $requestStack = null,
        int $stackContextLines = 5,
        private readonly ?Scope $scope = null,
        ?TraceIdProvider $traceIdProvider = null,
        ?ClockInterface $clock = null,
    ) {
        $this->requestProvider = $requestStack instanceof RequestStack
            ? new SymfonyRequestContextProvider($requestStack, $sendOptions->client)
            : null;

        $bundleSrc                 = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $this->implementationPaths = [
            dirname((string) (new ReflectionClass(CoreEnvelopeBuilder::class))->getFileName(), 2) . DIRECTORY_SEPARATOR,
            $bundleSrc,
        ];

        $this->core = new CoreEnvelopeBuilder(
            environment: $environment,
            release: $release,
            serverName: $serverName,
            sendOptions: new CoreSendOptions(
                environment: $sendOptions->environment,
                release: $sendOptions->release,
                serverName: $sendOptions->serverName,
                stacktrace: $sendOptions->stacktrace,
                request: $sendOptions->request,
                user: $sendOptions->user,
                runtime: $sendOptions->runtime,
                framework: $sendOptions->framework,
                os: $sendOptions->os,
                client: $sendOptions->client,
                stackVars: $sendOptions->stackVars,
            ),
            userContextProvider: $userContextProvider,
            breadcrumbBuffer: $breadcrumbBuffer,
            requestProvider: $this->requestProvider,
            stackContextLines: $stackContextLines,
            scope: $scope,
            traceIdProvider: $traceIdProvider,
            clock: $clock,
            dsnMode: EnvelopeDsnMode::None,
            framework: class_exists(Kernel::class) ? ['name' => 'symfony', 'version' => Kernel::VERSION] : null,
            culpritThrowableResolver: static fn (Throwable $throwable): Throwable => $throwable instanceof HandlerFailedException
                ? MessengerContext::rootCause($throwable)
                : $throwable,
            internalClassPrefixes: ['Nowo\\BeaconBundle\\'],
            internalPaths: [$bundleSrc],
        );
    }

    /**
     * Build a 3-line NDJSON envelope for an event (message and/or exception).
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     */
    public function buildEventEnvelope(
        BeaconDsn $dsn,
        string $message,
        string $level = 'error',
        ?Throwable $throwable = null,
        array $extra = [],
        ?array $fingerprint = null,
    ): string {
        return self::encode($this->eventEnvelope($dsn, $message, $level, $throwable, $extra, $fingerprint));
    }

    /**
     * Event envelope before encoding ({@see BeaconClient} scrubs its payload).
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     *
     * @internal
     */
    public function eventEnvelope(
        BeaconDsn $dsn,
        string $message,
        string $level = 'error',
        ?Throwable $throwable = null,
        array $extra = [],
        ?array $fingerprint = null,
    ): Envelope {
        // Raw frames of the current stack (message events with stack vars): the core builder reads its own copy.
        $currentTrace = !$throwable instanceof Throwable && $this->sendOptions->stacktrace && $this->sendOptions->stackVars
            ? $this->currentTrace()
            : null;
        $callerExtra = $extra + ($this->scope?->getExtra() ?? []);

        $envelope = $this->core->eventEnvelope($dsn, $message, $level, $throwable, $extra, $fingerprint);
        $payload  = $envelope->payload;

        $payload['message'] = SensitiveValueRedactor::redactMessage(
            $throwable instanceof Throwable && $message === '' ? $throwable->getMessage() : $message,
        );
        if ($throwable instanceof Throwable && isset($payload['exception']) && is_array($payload['exception'])) {
            $payload['exception'] = $this->redactExceptions($payload['exception'], $throwable);
        }
        if ($currentTrace !== null && isset($payload['stacktrace']) && is_array($payload['stacktrace'])) {
            $payload['stacktrace'] = $this->redactFrameVars($payload['stacktrace'], $currentTrace);
        }

        return $envelope->withPayload($this->redactRequest($payload, $callerExtra));
    }

    /**
     * Builds a performance transaction Envelope item (Beacon `type: transaction`).
     *
     * @param list<array{
     *     op?: string,
     *     description?: string,
     *     span_id?: string,
     *     start_timestamp?: float,
     *     timestamp?: float
     * }> $spans
     * @param array<string, mixed> $extra
     */
    public function buildTransactionEnvelope(
        BeaconDsn $dsn,
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): string {
        return self::encode($this->transactionEnvelope($dsn, $transactionName, $startTimestamp, $endTimestamp, $spans, $extra));
    }

    /**
     * Transaction envelope before encoding.
     *
     * @param list<array<string, mixed>> $spans
     * @param array<string, mixed> $extra
     *
     * @internal
     */
    public function transactionEnvelope(
        BeaconDsn $dsn,
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): Envelope {
        $envelope = $this->core->transactionEnvelope($dsn, $transactionName, $startTimestamp, $endTimestamp, $spans, $extra);

        return $envelope->withPayload($this->redactRequest($envelope->payload, $extra));
    }

    /**
     * Build a 3-line envelope with a `queue_snapshot` item: Messenger message counts per transport.
     *
     * Numbers only — no message bodies, ids or payloads. Beacon stores the latest snapshot per
     * transport and can open an issue when a failure transport crosses the project threshold.
     *
     * @param list<array{name: string, count: int|null, failure: bool}> $transports `count` is null when the
     *                                                                              transport cannot count messages
     */
    public function buildQueueSnapshotEnvelope(BeaconDsn $dsn, array $transports): string
    {
        return self::encode($this->core->queueSnapshotEnvelope($dsn, $transports));
    }

    /**
     * Build a 3-line envelope with a `deploy` item (release marker for Beacon analytics / suspect release).
     *
     * @param array{release: string, environment?: string|null, commit?: string|null, url?: string|null, name?: string|null, deployed_at?: float|null} $deploy
     */
    public function buildDeployEnvelope(BeaconDsn $dsn, array $deploy): string
    {
        return self::encode($this->core->deployEnvelope($dsn, $deploy));
    }

    /**
     * Build a 3-line envelope with a `schedule_catalog` item (Symfony Scheduler check-ins).
     *
     * Schedule names, recurring ids, message classes, command names and trigger descriptions only —
     * never message payloads or command arguments.
     *
     * @param list<array{name: string, entries: list<array<string, mixed>>}> $schedules
     */
    public function buildScheduleCatalogEnvelope(BeaconDsn $dsn, array $schedules): string
    {
        return self::encode($this->core->scheduleCatalogEnvelope($dsn, $schedules));
    }

    /**
     * Build a 3-line envelope with a `check_in` item (one Symfony Scheduler run status).
     *
     * @param array<string, mixed> $checkIn
     */
    public function buildCheckInEnvelope(BeaconDsn $dsn, array $checkIn): string
    {
        return self::encode($this->core->checkInEnvelope($dsn, $checkIn));
    }

    /**
     * NDJSON body (`header\nitem\npayload\n`) with the bundle's JSON flags.
     *
     * @throws RuntimeException when a line cannot be encoded (e.g. invalid UTF-8)
     *
     * @internal
     */
    public static function encode(Envelope $envelope): string
    {
        return self::encodeLine($envelope->header) . "\n"
            . self::encodeLine($envelope->itemHeader) . "\n"
            . self::encodeLine($envelope->payload) . "\n";
    }

    /**
     * Encode one envelope line as JSON.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws RuntimeException
     *
     * @internal
     */
    public static function encodeLine(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode envelope JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Exception values and frame vars with the bundle's redaction, from the raw throwable chain.
     *
     * @param array<array-key, mixed> $exception
     *
     * @return array<array-key, mixed>
     */
    private function redactExceptions(array $exception, Throwable $throwable): array
    {
        $chain = [];
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            $chain[] = $current;
        }

        /** @var list<array<string, mixed>> $values */
        $values = $exception['values'] ?? [];
        // The builder lists the chain innermost first (the reverse of getPrevious() order).
        foreach (array_reverse($chain) as $position => $current) {
            $value          = $values[$position] ?? [];
            $value['value'] = SensitiveValueRedactor::redactMessage($current->getMessage());
            if (isset($value['stacktrace']) && is_array($value['stacktrace'])) {
                /** @var list<array<string, mixed>> $rawFrames */
                $rawFrames           = [['file' => $current->getFile(), 'line' => $current->getLine(), 'function' => null], ...$current->getTrace()];
                $value['stacktrace'] = $this->redactFrameVars($value['stacktrace'], $rawFrames);
            }
            $values[$position] = $value;
        }
        $exception['values'] = $values;

        return $exception;
    }

    /**
     * Frame `vars` re-serialized from the raw frames with the bundle's {@see FrameVarsSerializer} (the builder lists
     * frames outermost first, the reverse of PHP's order).
     *
     * @param array<array-key, mixed> $stacktrace
     * @param list<array<string, mixed>> $rawFrames innermost first (PHP order)
     *
     * @return array<array-key, mixed>
     */
    private function redactFrameVars(array $stacktrace, array $rawFrames): array
    {
        /** @var list<array<string, mixed>> $frames */
        $frames = $stacktrace['frames'] ?? [];
        $raw    = array_reverse($rawFrames);
        $exact  = count($raw) === count($frames);

        foreach ($frames as $index => $frame) {
            if (!isset($frame['vars']) || !is_array($frame['vars'])) {
                continue;
            }

            /** @var array<string, mixed> $vars */
            $vars = $frame['vars'];
            // Should the frame lists ever differ, the bundle's rules still apply to the vars the core serialized.
            $frames[$index]['vars'] = $exact ? FrameVarsSerializer::fromPhpFrame($raw[$index]) : SensitiveValueRedactor::redactMap($vars);
        }
        $stacktrace['frames'] = $frames;

        return $stacktrace;
    }

    /**
     * Request URL / query string / referer with the bundle's redaction (in `request`, `contexts.request` and the
     * `extra.request_uri` the builder added).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $callerExtra extra values present before the builder added its own
     *
     * @return array<string, mixed>
     */
    private function redactRequest(array $payload, array $callerExtra): array
    {
        $request = $this->requestProvider?->currentRequest();
        if (!isset($payload['request']) || !is_array($payload['request']) || !$request instanceof Request) {
            return $payload;
        }

        $context                 = $payload['request'];
        $context['url']          = SensitiveValueRedactor::redactUrl($request->getUri());
        $context['query_string'] = SensitiveValueRedactor::redactQueryString($request->getQueryString() ?? '');
        $referer                 = SymfonyRequestContextProvider::referer($request);
        if ($referer !== null && isset($context['headers']) && is_array($context['headers'])) {
            $context['headers']['referer'] = SensitiveValueRedactor::redactUrl($referer);
        }

        $payload['request'] = $context;
        if (isset($payload['contexts']) && is_array($payload['contexts'])) {
            $payload['contexts']['request'] = $context;
        }
        if (!array_key_exists('request_uri', $callerExtra) && isset($payload['extra']) && is_array($payload['extra'])) {
            $payload['extra']['request_uri'] = $context['url'];
        }

        return $payload;
    }

    /**
     * The current stack (with arguments) without implementation frames, filtered as the core builder filters it.
     *
     * @return list<array<string, mixed>>
     */
    private function currentTrace(): array
    {
        return array_values(array_filter(
            debug_backtrace(),
            fn (array $frame): bool => !$this->isImplementationFrame($frame),
        ));
    }

    /**
     * Same rule as the core builder: beacon-php / bundle classes (not beacon-php's tests) and files under their `src/`.
     *
     * @param array<string, mixed> $frame
     */
    private function isImplementationFrame(array $frame): bool
    {
        $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        if (str_starts_with($class, 'Nowo\\BeaconBundle\\')
            || (str_starts_with($class, 'Nowo\\Beacon\\') && !str_starts_with($class, 'Nowo\\Beacon\\Tests\\'))) {
            return true;
        }

        $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
        foreach ($this->implementationPaths as $path) {
            if ($file !== '' && str_starts_with($file, $path)) {
                return true;
            }
        }

        return false;
    }
}
