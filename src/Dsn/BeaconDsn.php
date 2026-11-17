<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use LogicException;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\BeaconBundle\Ingest\IngestPaths;
use SensitiveParameter;
use SensitiveParameterValue;

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
 * Since 1.27.0 this class wraps beacon-php's {@see Dsn} (composition: it is no longer a subclass, beacon-php 0.3 made
 * `Dsn` final) and delegates every getter to it. Code that needs the core type calls {@see toCoreDsn()}.
 *
 * The secret stays in a {@see SensitiveParameterValue} (held by the core `Dsn`), so `var_dump()`, `print_r()`,
 * `var_export()`, Symfony VarDumper (`dump()`, profiler) and exception traces never show it, and neither this object
 * nor the core one can be serialized or unserialized.
 */
final class BeaconDsn
{
    private readonly Dsn $dsn;

    /**
     * @param int|string $projectId Positive numeric legacy id or canonical project UUID
     */
    public function __construct(
        string $scheme,
        string $publicKey,
        #[SensitiveParameter]
        string $secretKey,
        string $host,
        ?int $port,
        string|int $projectId,
    ) {
        $this->dsn = new Dsn($scheme, $publicKey, $secretKey, $host, $port, $projectId);
    }

    /**
     * Wrap the values of a core DSN (secret included).
     */
    public static function fromCoreDsn(Dsn $dsn): self
    {
        return new self($dsn->getScheme(), $dsn->getPublicKey(), $dsn->getSecretKey(), $dsn->getHost(), $dsn->getPort(), $dsn->getProjectId());
    }

    /**
     * The wrapped beacon-php {@see Dsn} (secret included), for code that takes the core type.
     */
    public function toCoreDsn(): Dsn
    {
        return $this->dsn;
    }

    /**
     * URL scheme (`http` or `https`).
     */
    public function getScheme(): string
    {
        return $this->dsn->getScheme();
    }

    /**
     * Public key (DSN userinfo username).
     */
    public function getPublicKey(): string
    {
        return $this->dsn->getPublicKey();
    }

    /**
     * Secret key (DSN userinfo password). Required by Beacon ingest.
     */
    public function getSecretKey(): string
    {
        return $this->dsn->getSecretKey();
    }

    /**
     * Lower-cased host name.
     */
    public function getHost(): string
    {
        return $this->dsn->getHost();
    }

    /**
     * Explicit port, or null for the scheme default.
     */
    public function getPort(): ?int
    {
        return $this->dsn->getPort();
    }

    /**
     * Project id: a positive numeric legacy id or a lower-cased canonical UUID.
     */
    public function getProjectId(): string
    {
        return $this->dsn->getProjectId();
    }

    /**
     * `{scheme}://{host}[:{port}]`.
     */
    public function getOrigin(): string
    {
        return $this->dsn->getOrigin();
    }

    /**
     * Legacy envelope URL `{origin}/api/{project_id}/envelope/` (see {@see IngestPaths}).
     */
    public function getEnvelopeUrl(): string
    {
        return $this->dsn->getEnvelopeUrl();
    }

    /**
     * Wire value for the `X-Beacon-Auth` HTTP header (public + secret).
     */
    public function getBeaconAuthHeader(): string
    {
        return $this->dsn->getBeaconAuthHeader();
    }

    /**
     * DSN string without the secret: `{scheme}://{public_key}@{host}[:{port}]/{project_id}`.
     */
    public function toPublicString(): string
    {
        return $this->dsn->toPublicString();
    }

    /**
     * Rebuild a canonical DSN string. It includes the secret: never log or send it (the Envelope header no longer
     * carries it since 1.21).
     */
    public function toString(): string
    {
        return $this->dsn->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['origin' => $this->getOrigin(), 'project_id' => $this->getProjectId(), 'public_key' => $this->getPublicKey(), 'secret_key' => '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('A Beacon DSN holds a secret and cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A Beacon DSN holds a secret and cannot be unserialized.');
    }
}
