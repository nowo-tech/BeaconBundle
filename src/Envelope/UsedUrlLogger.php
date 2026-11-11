<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Psr\Log\LoggerInterface;
use Stringable;

use function array_key_exists;

/**
 * Forwards log records, each to the same logger method, and replaces their `url` context value with the ingest URL
 * actually used (versioned or legacy, audit LO-I2). beacon-php's `IngestResponseHandler` always writes the legacy
 * envelope URL there.
 *
 * @internal built per response by {@see EnvelopeTransport}; holds the URL only (no credentials)
 */
final class UsedUrlLogger implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly string $url,
    ) {
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->inner->emergency($message, $this->context($context));
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->inner->alert($message, $this->context($context));
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->inner->critical($message, $this->context($context));
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->inner->error($message, $this->context($context));
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->inner->warning($message, $this->context($context));
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->inner->notice($message, $this->context($context));
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->inner->info($message, $this->context($context));
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->inner->debug($message, $this->context($context));
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $this->context($context));
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function context(array $context): array
    {
        if (array_key_exists('url', $context)) {
            $context['url'] = $this->url;
        }

        return $context;
    }
}
