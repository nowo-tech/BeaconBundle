<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Credentials;

use Closure;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsStoreInterface;
use RuntimeException;

/**
 * Plain-PHP credentials store (the DDD host's adapter in tests): one record in memory, read counter, optional failure.
 */
class InMemoryCredentialsStore implements BeaconCredentialsStoreInterface
{
    public int $reads = 0;

    public int $saves = 0;

    public bool $failReads = false;

    public bool $failSaves = false;

    /** @var (Closure(): void)|null called inside current(), e.g. to simulate re-entrant logging */
    public ?Closure $onRead = null;

    public function __construct(public ?BeaconCredentials $record = null)
    {
    }

    public function current(): ?BeaconCredentials
    {
        ++$this->reads;
        if ($this->onRead instanceof Closure) {
            ($this->onRead)();
        }

        if ($this->failReads) {
            throw new RuntimeException('SQLSTATE[HY000] connection to db failed for user=app password=fixture-db-password');
        }

        return $this->record;
    }

    public function save(BeaconCredentials $credentials): void
    {
        if ($this->failSaves) {
            throw new RuntimeException('read-only store');
        }

        ++$this->saves;
        $this->record = $credentials->isEmpty() ? null : $credentials;
    }
}
