<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

/**
 * Parsed Beacon DSN (authority + project id).
 *
 * Format: `{scheme}://{public_key}[:{secret}]@{host}[:{port}]/{project_id}`
 *
 * Examples:
 * - `https://9cb5e28adc3ed7a40052e2a17e327220@localhost:9444/1`
 * - `https://PUBLIC@beacon.example.com/3`
 * - `http://PUBLIC:SECRET@beacon.internal:9081/2`
 */
final class BeaconDsn
{
    public function __construct(
        private readonly string $scheme,
        private readonly string $publicKey,
        private readonly ?string $secretKey,
        private readonly string $host,
        private readonly ?int $port,
        private readonly int $projectId,
    ) {
    }

    /**
     * URL scheme (`http` or `https`).
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Public key (DSN userinfo username).
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Optional secret key (DSN userinfo password).
     */
    public function getSecretKey(): ?string
    {
        return $this->secretKey;
    }

    /**
     * Hostname from the DSN authority.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Explicit port when present in the DSN.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Numeric Beacon project id.
     */
    public function getProjectId(): int
    {
        return $this->projectId;
    }

    /**
     * Base origin including scheme, host, and optional port (no path).
     */
    public function getOrigin(): string
    {
        $authority = $this->host;
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $this->scheme . '://' . $authority;
    }

    /**
     * Envelope ingest URL: `{origin}/api/{projectId}/envelope/`.
     */
    public function getEnvelopeUrl(): string
    {
        return $this->getOrigin() . '/api/' . $this->projectId . '/envelope/';
    }

    /**
     * Rebuild a canonical DSN string (secret included when present).
     */
    public function toString(): string
    {
        $userInfo = $this->publicKey;
        if ($this->secretKey !== null && $this->secretKey !== '') {
            $userInfo .= ':' . $this->secretKey;
        }

        return $this->scheme . '://' . $userInfo . '@' . $this->host
            . ($this->port !== null ? ':' . $this->port : '')
            . '/' . $this->projectId;
    }
}
