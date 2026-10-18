<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use InvalidArgumentException;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use SensitiveParameter;

use function is_string;
use function preg_match;
use function strcasecmp;
use function trim;

/**
 * Beacon DSN + worker key of `messenger.remote_retry`, resolved lazily at runtime (both usually come
 * from env vars, so an empty or malformed value disables the feature with one explanation instead
 * of failing container compilation).
 *
 * The resolved value depends only on the process environment, so it is computed once per process.
 */
final class RemoteRetryCredentials
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private bool $resolved = false;

    private ?BeaconDsn $dsn = null;

    private ?WorkerKey $workerKey = null;

    private ?string $problem = null;

    public function __construct(
        private readonly BeaconDsnParser $parser,
        #[SensitiveParameter]
        private readonly mixed $rawDsn,
        #[SensitiveParameter]
        private readonly mixed $rawWorkerKey,
    ) {
    }

    /**
     * Why remote commands cannot run (null when DSN and worker key are usable).
     */
    public function problem(): ?string
    {
        $this->resolve();

        return $this->problem;
    }

    public function dsn(): ?BeaconDsn
    {
        $this->resolve();

        return $this->dsn;
    }

    public function workerKey(): ?WorkerKey
    {
        $this->resolve();

        return $this->workerKey;
    }

    /**
     * Whether a command's `project` belongs to the DSN project.
     *
     * With a UUID DSN the values must match (case-insensitive). With a legacy numeric DSN id the
     * client cannot know the project UUID; the per-project signing key already binds the command
     * to this project, so the check passes.
     */
    public function matchesProject(string $project): bool
    {
        $dsn = $this->dsn();
        if (!$dsn instanceof BeaconDsn) {
            return false;
        }

        $own = $dsn->getProjectId();
        if (preg_match(self::UUID_PATTERN, $own) !== 1) {
            return $project !== '';
        }

        return strcasecmp($own, $project) === 0;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        // @igor-ignore - Derived once per process from immutable configuration / env (no request data).
        $this->resolved = true;

        $dsn = is_string($this->rawDsn) ? trim($this->rawDsn) : '';
        if ($dsn === '') {
            // @igor-ignore - Derived once per process from immutable configuration / env.
            $this->problem = 'BEACON_DSN is empty: remote Messenger commands need the Beacon DSN.';

            return;
        }

        try {
            // @igor-ignore - Derived once per process from immutable configuration / env.
            $this->dsn = $this->parser->parse($dsn);
        } catch (InvalidBeaconDsnException $e) {
            // @igor-ignore - Derived once per process from immutable configuration / env.
            $this->problem = 'Invalid Beacon DSN: ' . $e->getMessage();

            return;
        }

        $key = is_string($this->rawWorkerKey) ? trim($this->rawWorkerKey) : '';
        if ($key === '') {
            // @igor-ignore - Derived once per process from immutable configuration / env.
            $this->problem = 'nowo_beacon.messenger.remote_retry.worker_key is empty (set BEACON_WORKER_KEY).';

            return;
        }

        try {
            // @igor-ignore - Derived once per process from immutable configuration / env.
            $this->workerKey = new WorkerKey($key);
        } catch (InvalidArgumentException $e) {
            // @igor-ignore - Derived once per process from immutable configuration / env.
            $this->problem = $e->getMessage();
        }
    }
}
