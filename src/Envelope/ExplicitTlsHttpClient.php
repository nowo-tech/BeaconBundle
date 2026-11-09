<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Sets `verify_peer` / `verify_host` on every request, so a host-wide `framework.http_client` default
 * (`verify_peer: false`) never weakens TLS for Beacon ingest (REQ-SEC-004 L6). beacon-php's transport only sets them
 * when verification is turned off.
 *
 * @internal used by {@see EnvelopeTransport}
 */
final class ExplicitTlsHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly bool $verifyPeer,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['verify_peer'] = $this->verifyPeer;
        $options['verify_host'] = $this->verifyPeer;

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->verifyPeer);
    }
}
