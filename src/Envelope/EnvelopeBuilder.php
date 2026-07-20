<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use RuntimeException;
use Throwable;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Builds Envelope NDJSON payloads for Beacon ingest.
 */
final class EnvelopeBuilder
{
    public function __construct(
        private readonly string $environment = 'prod',
        private readonly ?string $release = null,
        private readonly string $serverName = 'unknown',
    ) {
    }

    /**
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
        $eventId = $this->generateEventId();
        $sentAt  = gmdate('Y-m-d\TH:i:s\Z');

        $envelopeHeader = [
            'event_id' => $eventId,
            'dsn'      => $dsn->toString(),
            'sent_at'  => $sentAt,
        ];

        $payload = [
            'event_id'    => $eventId,
            'timestamp'   => microtime(true),
            'platform'    => 'php',
            'level'       => $level,
            'logger'      => 'nowo.beacon',
            'server_name' => $this->serverName,
            'environment' => $this->environment,
            'message'     => $message,
        ];

        if ($this->release !== null && $this->release !== '') {
            $payload['release'] = $this->release;
        }

        if ($extra !== []) {
            $payload['extra'] = $extra;
        }

        if ($fingerprint !== null && $fingerprint !== []) {
            $payload['fingerprint'] = $fingerprint;
        }

        if ($throwable !== null) {
            $payload['exception'] = [
                'values' => $this->serializeExceptions($throwable),
            ];
            $payload['culprit'] = $this->guessCulprit($throwable);
            if ($message === '') {
                $payload['message'] = $throwable->getMessage();
            }
        }

        $itemHeader = ['type' => 'event', 'content_type' => 'application/json'];

        return $this->encode($envelopeHeader) . "\n"
            . $this->encode($itemHeader) . "\n"
            . $this->encode($payload) . "\n";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeExceptions(Throwable $throwable): array
    {
        $values  = [];
        $current = $throwable;

        while ($current !== null) {
            $values[] = [
                'type'       => $current::class,
                'value'      => $current->getMessage(),
                'stacktrace' => [
                    'frames' => $this->framesFromTrace($current),
                ],
            ];
            $current = $current->getPrevious();
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

        $frames[] = [
            'filename' => $throwable->getFile(),
            'lineno'   => $throwable->getLine(),
            'function' => null,
            'in_app'   => true,
        ];

        foreach ($trace as $frame) {
            $frames[] = [
                'filename' => $frame['file'] ?? '[internal]',
                'lineno'   => $frame['line'] ?? 0,
                'function' => isset($frame['class'], $frame['type'], $frame['function'])
                    ? $frame['class'] . $frame['type'] . $frame['function']
                    : ($frame['function'] ?? null),
                'in_app' => isset($frame['file']),
            ];
        }

        return array_reverse($frames);
    }

    private function guessCulprit(Throwable $throwable): string
    {
        return $this->formatCulprit($throwable->getTrace(), $throwable->getFile(), $throwable->getLine());
    }

    /**
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

    private function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
