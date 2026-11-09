<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use LogicException;
use Nowo\Beacon\Dsn\Dsn;
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
 * Extends beacon-php's {@see Dsn}, so the core transports and builders accept it. The secret is held here in a
 * {@see SensitiveParameterValue} (the parent only ever stores an empty string), so `var_dump()`, `print_r()`,
 * `var_export()`, Symfony VarDumper (`dump()`, profiler) and exception traces never show it, and the object cannot be
 * serialized.
 */
final class BeaconDsn extends Dsn
{
    private readonly SensitiveParameterValue $secret;

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
        parent::__construct($scheme, $publicKey, '', $host, $port, $projectId);
        $this->secret = new SensitiveParameterValue($secretKey);
    }

    /**
     * Secret key (DSN userinfo password). Required by Beacon ingest.
     */
    public function getSecretKey(): string
    {
        return (string) $this->secret->getValue();
    }

    /**
     * Wire value for the `X-Beacon-Auth` HTTP header (public + secret).
     */
    public function getBeaconAuthHeader(): string
    {
        return 'Beacon beacon_key=' . $this->getPublicKey() . ', beacon_secret=' . $this->getSecretKey();
    }

    /**
     * Rebuild a canonical DSN string. It includes the secret: never log or send it (the Envelope header no longer
     * carries it since 1.21).
     */
    public function toString(): string
    {
        return $this->getScheme() . '://' . $this->getPublicKey() . ':' . $this->getSecretKey() . '@' . $this->getHost()
            . ($this->getPort() !== null ? ':' . $this->getPort() : '')
            . '/' . $this->getProjectId();
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
}
