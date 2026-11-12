<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Credentials;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

use function trim;

/**
 * Beacon credentials returned by a {@see BeaconCredentialsProviderInterface}: the DSN and the worker key, each with an
 * optional previous value that stays usable until its expiry (key rotation).
 *
 * Plain PHP value object (no Symfony, Doctrine or HTTP type). Each value is optional: a null DSN or worker key means
 * "nothing stored here", and the bundle uses `nowo_beacon.dsn` / `messenger.remote_retry.worker_key` for it. Blank
 * strings count as null. A previous value without an expiry is ignored by the bundle (a rotation always ends).
 *
 * The raw values are held in {@see SensitiveParameterValue}s: `var_dump()`, `print_r()`, `var_export()`, Symfony
 * VarDumper and exception traces never show them, and the object cannot be serialized.
 */
final class BeaconCredentials
{
    private readonly SensitiveParameterValue $dsn;

    private readonly SensitiveParameterValue $workerKey;

    private readonly SensitiveParameterValue $previousDsn;

    private readonly SensitiveParameterValue $previousWorkerKey;

    public function __construct(
        #[SensitiveParameter]
        ?string $dsn = null,
        #[SensitiveParameter]
        ?string $workerKey = null,
        #[SensitiveParameter]
        ?string $previousDsn = null,
        private readonly ?DateTimeImmutable $previousDsnExpiresAt = null,
        #[SensitiveParameter]
        ?string $previousWorkerKey = null,
        private readonly ?DateTimeImmutable $previousWorkerKeyExpiresAt = null,
    ) {
        $this->dsn               = new SensitiveParameterValue($this->normalize($dsn));
        $this->workerKey         = new SensitiveParameterValue($this->normalize($workerKey));
        $this->previousDsn       = new SensitiveParameterValue($this->normalize($previousDsn));
        $this->previousWorkerKey = new SensitiveParameterValue($this->normalize($previousWorkerKey));
    }

    /**
     * Current Beacon DSN (`https://PUBLIC_KEY:SECRET_KEY@host/PROJECT`), or null to use the configured one.
     */
    public function dsn(): ?string
    {
        return $this->read($this->dsn);
    }

    /**
     * Current worker key (`bwk_…`), or null to use the configured one.
     */
    public function workerKey(): ?string
    {
        return $this->read($this->workerKey);
    }

    /**
     * DSN replaced by the last rotation, used once when Beacon answers HTTP 401 to the current one.
     */
    public function previousDsn(): ?string
    {
        return $this->read($this->previousDsn);
    }

    /**
     * Until when {@see previousDsn()} may be used (null: it is ignored).
     */
    public function previousDsnExpiresAt(): ?DateTimeImmutable
    {
        return $this->previousDsnExpiresAt;
    }

    /**
     * Worker key replaced by the last rotation: command signatures made with it are accepted until its expiry.
     */
    public function previousWorkerKey(): ?string
    {
        return $this->read($this->previousWorkerKey);
    }

    /**
     * Until when {@see previousWorkerKey()} may be used (null: it is ignored).
     */
    public function previousWorkerKeyExpiresAt(): ?DateTimeImmutable
    {
        return $this->previousWorkerKeyExpiresAt;
    }

    /**
     * Whether no value at all is set.
     */
    public function isEmpty(): bool
    {
        return $this->dsn() === null && $this->workerKey() === null && $this->previousDsn() === null && $this->previousWorkerKey() === null;
    }

    /**
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'dsn'                            => $this->dsn() === null ? null : '***',
            'worker_key'                     => $this->workerKey() === null ? null : '***',
            'previous_dsn'                   => $this->previousDsn() === null ? null : '***',
            'previous_dsn_expires_at'        => $this->previousDsnExpiresAt?->format(DateTimeInterface::ATOM),
            'previous_worker_key'            => $this->previousWorkerKey() === null ? null : '***',
            'previous_worker_key_expires_at' => $this->previousWorkerKeyExpiresAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Beacon credentials cannot be serialized.');
    }

    private function normalize(#[SensitiveParameter] ?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    private function read(SensitiveParameterValue $value): ?string
    {
        $raw = $value->getValue();

        return $raw === null ? null : (string) $raw;
    }
}
