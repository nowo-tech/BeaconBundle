<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

use function is_int;

/**
 * Parsed Beacon DSN (authority + project id).
 *
 * Format: `{scheme}://{public_key}:{secret}@{host}[:{port}]/{project_id}`
 *
 * `project_id` is a positive numeric legacy id or a canonical project UUID.
 *
 * Examples:
 * - `https://PUBLIC:SECRET@localhost:9444/1`
 * - `https://PUBLIC:SECRET@localhost:9447/019fea2d-507b-7890-8b33-ca488db6f696`
 * - `http://PUBLIC:SECRET@beacon.internal:9081/2`
 *
 * The secret is held in a {@see SensitiveParameterValue}, so `var_dump()`, `print_r()`, `var_export()`, Symfony
 * VarDumper (`dump()`, profiler) and exception traces never show it, and the object cannot be serialized.
 */
final class BeaconDsn
{
    private readonly string $projectId;

    private readonly SensitiveParameterValue $secretKey;

    /**
     * @param int|string $projectId Positive numeric legacy id or canonical project UUID
     */
    public function __construct(
        private readonly string $scheme,
        private readonly string $publicKey,
        #[SensitiveParameter]
        string $secretKey,
        private readonly string $host,
        private readonly ?int $port,
        string|int $projectId,
    ) {
        $this->projectId = is_int($projectId) ? (string) $projectId : $projectId;
        $this->secretKey = new SensitiveParameterValue($secretKey);
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
     * Secret key (DSN userinfo password). Required by Symfony Beacon ingest.
     */
    public function getSecretKey(): string
    {
        return (string) $this->secretKey->getValue();
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
     * Beacon project id (positive numeric legacy string or canonical UUID).
     */
    public function getProjectId(): string
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
     * Wire value for the `X-Beacon-Auth` HTTP header (public + secret).
     */
    public function getBeaconAuthHeader(): string
    {
        return 'Beacon beacon_key=' . $this->publicKey . ', beacon_secret=' . $this->getSecretKey();
    }

    /**
     * Rebuild a canonical DSN string (always includes the secret).
     */
    public function toString(): string
    {
        return $this->scheme . '://' . $this->publicKey . ':' . $this->getSecretKey() . '@' . $this->host
            . ($this->port !== null ? ':' . $this->port : '')
            . '/' . $this->projectId;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['origin' => $this->getOrigin(), 'project_id' => $this->projectId, 'public_key' => $this->publicKey, 'secret_key' => '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('A Beacon DSN holds a secret and cannot be serialized.');
    }
}
