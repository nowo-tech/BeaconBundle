<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
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
 * Beacon Envelope ingest calls are skipped to avoid noisy self-traces.
 */
final class TraceableBeaconHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly SpanBuffer $spanBuffer,
        private readonly BreadcrumbBuffer $breadcrumbBuffer,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->shouldSkip($url, $options)) {
            return $this->client->request($method, $url, $options);
        }

        $start = microtime(true);
        $host  = $this->hostFromUrl($url);

        try {
            $response = $this->client->request($method, $url, $options);
            $status   = $response->getStatusCode();
            $this->record($method, $host, $start, microtime(true), $status);

            return $response;
        } catch (Throwable $exception) {
            $this->record($method, $host, $start, microtime(true), null, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options), $this->spanBuffer, $this->breadcrumbBuffer);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function shouldSkip(string $url, array $options): bool
    {
        if (str_contains($url, '/envelope/')) {
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

        $this->spanBuffer->add('http.client', $description, $start, $end, $data);
        $this->breadcrumbBuffer->add(
            $description,
            'http',
            $error !== null || ($status !== null && $status >= 400) ? 'error' : 'info',
            $data,
            'http',
        );
    }
}
