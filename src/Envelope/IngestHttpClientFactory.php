<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_values;

/**
 * Builds the HTTP client used for Envelope ingest (`transport.retry`).
 *
 * A server error from Beacon (by default 500, 502, 503, 504) is retried after a wait that grows with each attempt
 * (`delay_ms` × `multiplier`ⁿ, bounded by `max_delay_ms`, varied by `jitter`); a `Retry-After` header takes
 * precedence. Transport errors (DNS, refused connection, timeout) are not retried, so a receiver that is down does
 * not multiply the request time. Beacon stores one event per `event_id`, so a retried POST does not duplicate events.
 */
final class IngestHttpClientFactory
{
    /**
     * Wrap the client in a {@see RetryableHttpClient}, or return it unchanged when retries are disabled.
     *
     * @param list<int> $statusCodes
     */
    public static function create(
        HttpClientInterface $httpClient,
        int $maxRetries = 2,
        int $delayMs = 500,
        float $multiplier = 2.0,
        int $maxDelayMs = 5000,
        float $jitter = 0.1,
        array $statusCodes = [500, 502, 503, 504],
    ): HttpClientInterface {
        if ($maxRetries <= 0 || $statusCodes === []) {
            return $httpClient;
        }

        // A plain list of codes retries them for every method (POST included).
        $strategy = new GenericRetryStrategy(array_values($statusCodes), $delayMs, $multiplier, $maxDelayMs, $jitter);

        return new RetryableHttpClient($httpClient, $strategy, $maxRetries);
    }
}
