<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Credentials;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;

/**
 * Effective Beacon credentials at one point in time: the provider record merged over the configuration, parsed.
 *
 * `generation` changes whenever any effective value changes, so holders of transports or clients built from an older
 * snapshot know they must rebuild them. Never serialized; dumps show no secret ({@see BeaconDsn} and {@see WorkerKey}
 * mask theirs).
 *
 * @internal built by {@see BeaconCredentialsResolver}
 */
final class ResolvedBeaconCredentials
{
    public const SOURCE_CONFIG = 'config';

    public const SOURCE_PROVIDER = 'provider';

    public const SOURCE_NONE = 'none';

    /**
     * @param string $dsnSource One of the SOURCE_* constants
     * @param string $workerKeySource One of the SOURCE_* constants
     */
    public function __construct(
        public readonly int $generation,
        private readonly BeaconCredentials $effective,
        private readonly ?BeaconDsn $dsn,
        private readonly ?string $dsnProblem,
        public readonly string $dsnSource,
        private readonly ?WorkerKey $workerKey,
        private readonly ?string $workerKeyProblem,
        public readonly string $workerKeySource,
        private readonly ?BeaconDsn $previousDsn = null,
        private readonly ?WorkerKey $previousWorkerKey = null,
    ) {
    }

    /**
     * Current DSN, or null (see {@see dsnProblem()}).
     */
    public function dsn(): ?BeaconDsn
    {
        return $this->dsn;
    }

    /**
     * Why there is no usable DSN (null when {@see dsn()} is set).
     */
    public function dsnProblem(): ?string
    {
        return $this->dsnProblem;
    }

    /**
     * Current worker key, or null (see {@see workerKeyProblem()}).
     */
    public function workerKey(): ?WorkerKey
    {
        return $this->workerKey;
    }

    /**
     * Why there is no usable worker key (null when workerKey() returns a key).
     */
    public function workerKeyProblem(): ?string
    {
        return $this->workerKeyProblem;
    }

    /**
     * Previous DSN while it has not expired at `$now` (same origin and project as the current one).
     */
    public function previousDsn(DateTimeInterface $now): ?BeaconDsn
    {
        return $this->isLive($this->effective->previousDsnExpiresAt(), $now) ? $this->previousDsn : null;
    }

    /**
     * Previous worker key while it has not expired at `$now`.
     */
    public function previousWorkerKey(DateTimeInterface $now): ?WorkerKey
    {
        return $this->isLive($this->effective->previousWorkerKeyExpiresAt(), $now) ? $this->previousWorkerKey : null;
    }

    /**
     * Raw effective values (current DSN / worker key as used, previous values as accepted): what a rotation keeps as
     * "previous". Holds secrets: never log it.
     */
    public function effective(): BeaconCredentials
    {
        return $this->effective;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'generation'          => $this->generation,
            'dsn'                 => $this->dsn,
            'dsn_source'          => $this->dsnSource,
            'dsn_problem'         => $this->dsnProblem,
            'worker_key'          => $this->workerKey,
            'worker_key_source'   => $this->workerKeySource,
            'worker_key_problem'  => $this->workerKeyProblem,
            'previous_dsn'        => $this->previousDsn,
            'previous_worker_key' => $this->previousWorkerKey,
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Beacon credentials cannot be serialized.');
    }

    private function isLive(?DateTimeImmutable $expiresAt, DateTimeInterface $now): bool
    {
        return $expiresAt instanceof DateTimeImmutable && $expiresAt > $now;
    }
}
