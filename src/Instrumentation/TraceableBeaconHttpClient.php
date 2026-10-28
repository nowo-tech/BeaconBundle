<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use Closure;
use Generator;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function is_array;
use function is_string;
use function microtime;
use function parse_url;
use function str_contains;

use const PHP_URL_HOST;

/**
 * Decorates Symfony HttpClient to record opt-in outbound request spans / breadcrumbs.
 *
 * Beacon Envelope ingest and remote Messenger command calls are skipped to avoid noisy self-traces.
 *
 * 1.18.1: an async decorator — the span is recorded when the first chunk (the status) or a transport error arrives,
 * never by reading the response inside request(). Reading it there made the response unusable for an outer async
 * decorator (NoPrivateNetworkHttpClient, RetryableHttpClient: "A decorated client should not call any of the
 * response's methods in its request() method").
 */
final class TraceableBeaconHttpClient implements HttpClientInterface, ResetInterface
{
    use AsyncDecoratorTrait;

    public function __construct(
        HttpClientInterface $client,
        private readonly SpanBuffer $spanBuffer,
        private readonly BreadcrumbBuffer $breadcrumbBuffer,
    ) {
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->shouldSkip($url, $options)) {
            return new AsyncResponse($this->client, $method, $url, $options);
        }

        $start = microtime(true);
        $host  = $this->hostFromUrl($url);

        try {
            return new AsyncResponse($this->client, $method, $url, $options, $this->passthru($method, $host, $start));
        } catch (Throwable $exception) {
            $this->record($method, $host, $start, microtime(true), null, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * Records the span once, on the first chunk (with the status code) or on the transport error that replaces it.
     */
    private function passthru(string $method, string $host, float $start): Closure
    {
        $recorded = new class {
            public bool $done = false;
        };

        return function (ChunkInterface $chunk, AsyncContext $context) use ($method, $host, $start, $recorded): Generator {
            if (!$recorded->done) {
                try {
                    $first = $chunk->isFirst();
                } catch (TransportExceptionInterface $exception) {
                    $recorded->done = true;
                    $this->record($method, $host, $start, microtime(true), null, $exception->getMessage());

                    throw $exception;
                }
                if ($first) {
                    $recorded->done = true;
                    $this->record($method, $host, $start, microtime(true), $context->getStatusCode());
                }
            }

            yield $chunk;
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function shouldSkip(string $url, array $options): bool
    {
        if (str_contains($url, '/envelope/') || str_contains($url, '/messenger/commands/')) {
            return true;
        }

        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            return false;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'User-Agent') === 0) {
                $ua = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;

                return str_starts_with($ua, 'beacon-bundle/');
            }
            if (is_string($value) && str_starts_with(strtolower($value), 'user-agent:')
                && str_contains(strtolower($value), 'beacon-bundle/')
            ) {
                return true;
            }
        }

        return false;
    }

    private function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unknown';
    }

    private function record(
        string $method,
        string $host,
        float $start,
        float $end,
        ?int $status,
        ?string $error = null,
    ): void {
        $description = strtoupper($method) . ' ' . $host;
        $data        = [
            'http.method' => strtoupper($method),
            'http.host'   => $host,
        ];
        if ($status !== null) {
            $data['http.status_code'] = $status;
        }
        if ($error !== null) {
            $data['error'] = $error;
        }

        // @igor-ignore - Not shared worker service state.
        $this->spanBuffer->add('http.client', $description, $start, $end, $data);
        // @igor-ignore - Not shared worker service state.
        $this->breadcrumbBuffer->add(
            $description,
            'http',
            $error !== null || ($status !== null && $status >= 400) ? 'error' : 'info',
            $data,
            'http',
        );
    }
}
