<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use function is_numeric;
use function max;
use function strtotime;
use function time;

/**
 * Retry strategy for **synchronous** Envelope sends: a `Retry-After` longer than `transport.retry.max_delay_ms`
 * ends the retries instead of blocking the request (REQ-RUNTIME-001).
 *
 * Symfony's {@see RetryableHttpClient} waits for the full `Retry-After` instead of asking the strategy for a delay, so a 503 with `Retry-After: 300` would hold a web request or a FrankenPHP worker thread
 * for minutes per retry. This strategy keeps every decision of the inner strategy and only declines a retry whose
 * `Retry-After` exceeds the bound: the send fails at once with the server's status (logged as usual) rather than
 * retrying earlier than Beacon asked. A `Retry-After` within the bound is honoured as before.
 *
 * Background sends (`async` flush, Messenger handler) do not use this strategy and keep honouring the full
 * `Retry-After`.
 */
final class BoundedRetryAfterStrategy implements RetryStrategyInterface
{
    /**
     * @param int $maxDelayMs Longest `Retry-After` a synchronous send waits for, in milliseconds (0 = no bound)
     */
    public function __construct(
        private readonly RetryStrategyInterface $inner,
        private readonly int $maxDelayMs,
    ) {
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        $retry = $this->inner->shouldRetry($context, $responseContent, $exception);
        if ($retry !== true || $this->maxDelayMs <= 0) {
            return $retry;
        }

        $retryAfterMs = self::retryAfterMs($context->getHeaders());

        return $retryAfterMs === null || $retryAfterMs <= $this->maxDelayMs;
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->inner->getDelay($context, $responseContent, $exception);
    }

    /**
     * The wait a `Retry-After` header asks for, read the way RetryableHttpClient reads it (seconds or HTTP date).
     *
     * @param array<string, list<string>> $headers Lower-cased response headers
     */
    public static function retryAfterMs(array $headers): ?int
    {
        $after = $headers['retry-after'][0] ?? null;
        if ($after === null) {
            return null;
        }

        if (is_numeric($after)) {
            return (int) ($after * 1000);
        }

        $time = strtotime($after);

        return $time === false ? null : max(0, $time - time()) * 1000;
    }
}
