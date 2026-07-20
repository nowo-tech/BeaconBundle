<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;
use Throwable;

use function array_key_exists;
use function count;
use function dirname;
use function is_array;
use function is_string;

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const DIRECTORY_SEPARATOR;
use const FILE_IGNORE_NEW_LINES;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * Builds Envelope NDJSON payloads for Beacon ingest.
 */
final class EnvelopeBuilder
{
    /** @var array<string, list<string>|null> */
    private array $sourceLineCache = [];

    public function __construct(
        private readonly string $environment = 'prod',
        private readonly ?string $release = null,
        private readonly string $serverName = 'unknown',
        private readonly SendOptions $sendOptions = new SendOptions(),
        private readonly ?UserContextProviderInterface $userContextProvider = null,
        private readonly ?BreadcrumbBuffer $breadcrumbBuffer = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly int $stackContextLines = 5,
    ) {
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
        $eventId    = $this->generateEventId();
        $occurredAt = new DateTimeImmutable('now');
        $sentAt     = $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $timestamp  = (float) $occurredAt->format('U.u');

        $envelopeHeader = [
            'event_id' => $eventId,
            'dsn'      => $dsn->toString(),
            'sent_at'  => $sentAt,
        ];

        $payload = [
            'event_id'  => $eventId,
            'timestamp' => $timestamp,
            'datetime'  => $sentAt,
            'platform'  => 'php',
            'level'     => $level,
            'logger'    => 'nowo.beacon',
            'message'   => $message,
        ];

        if ($this->sendOptions->serverName) {
            $payload['server_name'] = $this->serverName;
        }

        if ($this->sendOptions->environment) {
            $payload['environment'] = $this->environment;
        }

        if ($this->sendOptions->release && $this->release !== null && $this->release !== '') {
            $payload['release'] = $this->release;
        }

        $contexts = $this->buildContexts();
        if ($contexts !== []) {
            $payload['contexts'] = $contexts;
        }

        if ($this->sendOptions->user && $this->userContextProvider instanceof UserContextProviderInterface) {
            $user = $this->userContextProvider->getUserContext();
            if ($user !== null && $user !== []) {
                $payload['user'] = $user;
            }
        }

        if ($extra !== []) {
            $payload['extra'] = $extra;
        }

        if ($fingerprint !== null && $fingerprint !== []) {
            $payload['fingerprint'] = $fingerprint;
        }

        $this->attachRequest($payload);
        $this->attachBreadcrumbs($payload);

        if ($throwable instanceof Throwable) {
            $payload['exception'] = [
                'values' => $this->serializeExceptions($throwable),
            ];
            if ($this->sendOptions->stacktrace) {
                $payload['culprit'] = $this->guessCulprit($throwable);
            }
            if ($message === '') {
                $payload['message'] = $throwable->getMessage();
            }
        } elseif ($this->sendOptions->stacktrace) {
            $this->attachCurrentStacktrace($payload);
        }

        $itemHeader = ['type' => 'event', 'content_type' => 'application/json'];

        return $this->encode($envelopeHeader) . "\n"
            . $this->encode($itemHeader) . "\n"
            . $this->encode($payload) . "\n";
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
        $eventId = $this->generateEventId();
        $sentAt  = (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        $envelopeHeader = [
            'event_id' => $eventId,
            'dsn'      => $dsn->toString(),
            'sent_at'  => $sentAt,
        ];

        $payload = [
            'event_id'        => $eventId,
            'type'            => 'transaction',
            'transaction'     => $transactionName,
            'start_timestamp' => $startTimestamp,
            'timestamp'       => $endTimestamp,
            'platform'        => 'php',
            'spans'           => $spans,
        ];

        if ($this->sendOptions->environment) {
            $payload['environment'] = $this->environment;
        }
        if ($this->sendOptions->release && $this->release !== null && $this->release !== '') {
            $payload['release'] = $this->release;
        }
        if ($extra !== []) {
            $payload['extra'] = $extra;
        }

        $this->attachRequest($payload);
        $this->attachBreadcrumbs($payload);

        $itemHeader = ['type' => 'transaction', 'content_type' => 'application/json'];

        return $this->encode($envelopeHeader) . "\n"
            . $this->encode($itemHeader) . "\n"
            . $this->encode($payload) . "\n";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachRequest(array &$payload): void
    {
        if (!$this->sendOptions->request || !$this->requestStack instanceof RequestStack) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return;
        }

        $requestContext = [
            'url'          => $request->getUri(),
            'method'       => $request->getMethod(),
            'query_string' => $request->getQueryString() ?? '',
        ];

        $headers = [];
        foreach ([
            'host',
            'user-agent',
            'accept',
            'accept-language',
            'accept-encoding',
            'content-type',
            'origin',
            'referer',
            'x-requested-with',
        ] as $headerName) {
            if ($request->headers->has($headerName)) {
                $headers[$headerName] = (string) $request->headers->get($headerName);
            }
        }
        if ($headers !== []) {
            $requestContext['headers'] = $headers;
        }

        $payload['request'] = $requestContext;

        if (!isset($payload['contexts']) || !is_array($payload['contexts'])) {
            $payload['contexts'] = [];
        }
        $payload['contexts']['request'] = $requestContext;

        // Keep listener-style keys when callers did not already set them in extra.
        if (!isset($payload['extra']) || !is_array($payload['extra'])) {
            $payload['extra'] = [];
        }
        $payload['extra'] += [
            'request_uri'    => $requestContext['url'],
            'request_method' => $requestContext['method'],
        ];
    }

    /**
     * Attaches a current PHP stacktrace for message events (no Throwable).
     *
     * @param array<string, mixed> $payload
     */
    private function attachCurrentStacktrace(array &$payload): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $trace = array_values(array_filter(
            $trace,
            fn (array $frame): bool => !$this->isBeaconBundleImplementationFrame($frame),
        ));

        if ($trace === []) {
            return;
        }

        $payload['stacktrace'] = [
            'frames' => $this->framesFromPhpTrace($trace),
        ];
        $payload['culprit'] = $this->formatCulprit($trace, (string) ($trace[0]['file'] ?? 'unknown'), (int) ($trace[0]['line'] ?? 0));
    }

    /**
     * True for BeaconBundle implementation frames (not tests or host app code).
     *
     * Must not match any path segment named "BeaconBundle" — CI checkouts live under
     * `…/BeaconBundle/BeaconBundle/…`, which would wipe the entire backtrace.
     *
     * @param array<string, mixed> $frame
     */
    private function isBeaconBundleImplementationFrame(array $frame): bool
    {
        $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        if ($class !== '' && str_starts_with($class, 'Nowo\\BeaconBundle\\')) {
            return true;
        }

        $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
        if ($file === '') {
            return false;
        }

        // EnvelopeBuilder lives in src/Envelope → package src/ root.
        $srcRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $srcRoot);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachBreadcrumbs(array &$payload): void
    {
        if (!$this->breadcrumbBuffer instanceof BreadcrumbBuffer) {
            return;
        }

        $crumbs = $this->breadcrumbBuffer->all();
        if ($crumbs === []) {
            return;
        }

        $payload['breadcrumbs'] = ['values' => $crumbs];
        $this->breadcrumbBuffer->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContexts(): array
    {
        $contexts = [];

        if ($this->sendOptions->runtime) {
            $contexts['runtime'] = [
                'name'    => 'php',
                'version' => PHP_VERSION,
            ];
        }

        if ($this->sendOptions->framework && class_exists(Kernel::class)) {
            $contexts['framework'] = [
                'name'    => 'symfony',
                'version' => Kernel::VERSION,
            ];
        }

        if ($this->sendOptions->os) {
            $contexts['os'] = [
                'name'    => PHP_OS_FAMILY,
                'version' => php_uname('r'),
            ];
        }

        return $contexts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeExceptions(Throwable $throwable): array
    {
        $values  = [];
        $current = $throwable;

        while ($current instanceof Throwable) {
            $entry = [
                'type'  => $current::class,
                'value' => $current->getMessage(),
            ];
            if ($this->sendOptions->stacktrace) {
                $entry['stacktrace'] = [
                    'frames' => $this->framesFromTrace($current),
                ];
            }
            $values[] = $entry;
            $current  = $current->getPrevious();
        }

        return array_reverse($values);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function framesFromTrace(Throwable $throwable): array
    {
        $frames = [];
        $trace  = $throwable->getTrace();

        $frames[] = $this->normalizeFrame([
            'file'     => $throwable->getFile(),
            'line'     => $throwable->getLine(),
            'function' => null,
        ]);

        foreach ($trace as $frame) {
            $frames[] = $this->normalizeFrame($frame);
        }

        return array_reverse($frames);
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    private function framesFromPhpTrace(array $trace): array
    {
        $frames = [];
        foreach ($trace as $frame) {
            $frames[] = $this->normalizeFrame($frame);
        }

        return array_reverse($frames);
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @return array<string, mixed>
     */
    private function normalizeFrame(array $frame): array
    {
        $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;
        $line = isset($frame['line']) ? (int) $frame['line'] : 0;

        $normalized = [
            'filename' => $file ?? '[internal]',
            'lineno'   => $line,
            'function' => isset($frame['class'], $frame['type'], $frame['function'])
                ? $frame['class'] . $frame['type'] . $frame['function']
                : ($frame['function'] ?? null),
            'in_app' => $this->isInApp($file),
        ];

        if ($file !== null) {
            $normalized['abs_path'] = $file;
        }

        if ($this->sendOptions->stacktrace && $file !== null && $line > 0) {
            $normalized += $this->readSourceContext($file, $line);
        }

        return $normalized;
    }

    /**
     * @return array{
     *     pre_context?: list<string>,
     *     context_line?: string,
     *     post_context?: list<string>
     * }
     */
    private function readSourceContext(string $file, int $lineno): array
    {
        $lines = $this->loadSourceLines($file);
        if ($lines === null) {
            return [];
        }

        $index = $lineno - 1;
        if ($index < 0 || $index >= count($lines)) {
            return [];
        }

        $context = max(0, $this->stackContextLines);
        $start   = max(0, $index - $context);
        $end     = min(count($lines) - 1, $index + $context);

        $pre = [];
        for ($i = $start; $i < $index; ++$i) {
            $pre[] = $lines[$i];
        }

        $post = [];
        for ($i = $index + 1; $i <= $end; ++$i) {
            $post[] = $lines[$i];
        }

        return [
            'pre_context'  => $pre,
            'context_line' => $lines[$index],
            'post_context' => $post,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function loadSourceLines(string $file): ?array
    {
        if (array_key_exists($file, $this->sourceLineCache)) {
            return $this->sourceLineCache[$file];
        }

        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return $this->sourceLineCache[$file] = null;
        }

        $size = filesize($file);
        if ($size === false || $size > 1_048_576) {
            return $this->sourceLineCache[$file] = null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $this->sourceLineCache[$file] = null;
        }

        return $this->sourceLineCache[$file] = $lines;
    }

    /**
     * Whether the path looks like application code (excludes vendor/cache/node_modules).
     */
    private function isInApp(?string $file): bool
    {
        if ($file === null || $file === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $file);
        foreach (['/vendor/', '/var/cache/', '/node_modules/'] as $exclude) {
            if (str_contains($normalized, $exclude)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Best-effort culprit from the top exception frame.
     */
    private function guessCulprit(Throwable $throwable): string
    {
        return $this->formatCulprit($throwable->getTrace(), $throwable->getFile(), $throwable->getLine());
    }

    /**
     * Human-readable culprit (class::method or file:line fallback).
     *
     * @param list<array<string, mixed>> $trace
     */
    private function formatCulprit(array $trace, string $file, int $line): string
    {
        if ($trace === []) {
            return $file . ':' . $line;
        }

        $top = $trace[0];
        if (isset($top['class'], $top['type'], $top['function'])) {
            return $top['class'] . $top['type'] . $top['function'];
        }

        return (string) ($top['function'] ?? $file);
    }

    /**
     * Encode a payload as a single JSON line for the envelope.
     *
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode envelope JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * 32-character hex event id.
     */
    private function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
