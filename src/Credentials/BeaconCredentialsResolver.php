<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Credentials;

use DateTimeImmutable;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use function hash_equals;
use function implode;
use function is_string;
use function max;
use function microtime;
use function trim;

/**
 * Effective Beacon credentials at runtime: the {@see BeaconCredentialsProviderInterface} record merged over the
 * configured `nowo_beacon.dsn` / `messenger.remote_retry.worker_key` (provider value wins; a missing, invalid or
 * unreadable provider value falls back to the configured one; neither: reporting is off).
 *
 * - Runtime only: nothing is read at container compile time, and the provider is consulted only once {@see arm()} ran
 *   (first request, console command or Messenger worker start), so errors raised while the kernel boots use the
 *   configuration. A call made while the provider is being read (its own logging, for instance) gets the last
 *   snapshot or the configuration, never a nested provider call.
 * - Cached for `credentials_cache_ttl` seconds (0 = every use re-reads the provider). The cache holds no request or user
 *   data and expires by time, so a FrankenPHP worker with the kernel kept alive and never reset picks up a rotation
 *   within the TTL; {@see reset()} (tagged `kernel.reset`) and {@see invalidate()} (settings changed) only make it
 *   expire now. It does not implement ResetInterface on purpose: a reset must never forget the credentials version
 *   (clients compare it) nor the provider-armed flag, so correctness never depends on the resetter.
 * - Never throws: a provider exception is logged once (class only, never its message) until a read succeeds again.
 *
 * Every log record carries {@see EnvelopeTransport::LOG_CONTEXT_FLAG}, so the Monolog handler never forwards it.
 */
final class BeaconCredentialsResolver
{
    public const DEFAULT_CACHE_TTL = 60;

    /** Minimum seconds between two forced refreshes ({@see refresh()}, used after an HTTP 401). */
    public const REFRESH_BACKOFF = 5.0;

    private readonly SensitiveParameterValue $configDsn;

    private readonly SensitiveParameterValue $configWorkerKey;

    private ?ResolvedBeaconCredentials $current = null;

    private float $expiresAt = 0.0;

    private ?float $lastRefreshAt = null;

    private int $generation = 0;

    private ?SensitiveParameterValue $fingerprint = null;

    private bool $armed = false;

    private bool $reading = false;

    /** @var array<string, true> warning codes already logged (a fixed, small set) */
    private array $warned = [];

    /** @var array<string, true> warning codes logged by the build in progress */
    private array $warnedNow = [];

    public function __construct(
        private readonly BeaconDsnParser $parser,
        #[SensitiveParameter]
        mixed $configDsn,
        #[SensitiveParameter]
        mixed $configWorkerKey = '',
        private readonly ?BeaconCredentialsProviderInterface $provider = null,
        private readonly int $cacheTtl = self::DEFAULT_CACHE_TTL,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->configDsn       = new SensitiveParameterValue(is_string($configDsn) ? trim($configDsn) : '');
        $this->configWorkerKey = new SensitiveParameterValue(is_string($configWorkerKey) ? trim($configWorkerKey) : '');
    }

    /**
     * Whether a credentials provider is configured.
     */
    public function hasProvider(): bool
    {
        return $this->provider instanceof BeaconCredentialsProviderInterface;
    }

    /**
     * Seconds a provider answer is reused.
     */
    public function cacheTtl(): int
    {
        return max(0, $this->cacheTtl);
    }

    /**
     * Allow provider reads from now on (called on the first request, console command or worker start).
     */
    public function arm(): void
    {
        if ($this->armed) {
            return;
        }

        // @igor-ignore - Process lifecycle flag (kernel booted), no request data.
        $this->armed = true;
        // @igor-ignore - Cache expiry timestamp, no request data.
        $this->expiresAt = 0.0;
    }

    /**
     * Effective credentials (cached for the TTL).
     *
     * @phpstan-impure
     */
    public function resolve(): ResolvedBeaconCredentials
    {
        if ($this->reading) {
            return $this->current ?? $this->build(null, false);
        }

        if ($this->current instanceof ResolvedBeaconCredentials && (!$this->provider instanceof BeaconCredentialsProviderInterface || !$this->armed || $this->seconds() < $this->expiresAt)) {
            return $this->current;
        }

        return $this->load();
    }

    /**
     * Re-read the provider now (at most once per {@see REFRESH_BACKOFF} seconds). Returns whether any effective value
     * changed.
     */
    public function refresh(): bool
    {
        if (!$this->provider instanceof BeaconCredentialsProviderInterface || !$this->armed || $this->reading) {
            return false;
        }

        $now = $this->seconds();
        if ($this->lastRefreshAt !== null && $now - $this->lastRefreshAt < self::REFRESH_BACKOFF) {
            return false;
        }

        // @igor-ignore - Backoff timestamp, no request data.
        $this->lastRefreshAt = $now;
        $before              = $this->current?->generation;

        return $this->load()->generation !== $before;
    }

    /**
     * Make the cache expire now (the next use re-reads the provider), e.g. after the stored credentials changed.
     */
    public function invalidate(): void
    {
        // @igor-ignore - Cache expiry timestamp, no request data.
        $this->expiresAt = 0.0;
    }

    /**
     * `kernel.reset`: same as {@see invalidate()}. Correctness never depends on it (the cache also expires by time).
     */
    public function reset(): void
    {
        $this->invalidate();
    }

    /**
     * Previous DSN while it is still valid.
     */
    public function previousDsn(): ?BeaconDsn
    {
        return $this->resolve()->previousDsn($this->now());
    }

    /**
     * Previous worker key while it is still valid.
     */
    public function previousWorkerKey(): ?WorkerKey
    {
        return $this->resolve()->previousWorkerKey($this->now());
    }

    /**
     * Current time (from the clock when one is set).
     */
    public function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider instanceof BeaconCredentialsProviderInterface ? $this->provider::class : null, 'cache_ttl' => $this->cacheTtl, 'current' => $this->current];
    }

    private function load(): ResolvedBeaconCredentials
    {
        $record   = null;
        $consults = $this->provider instanceof BeaconCredentialsProviderInterface && $this->armed;
        if ($consults) {
            // @igor-ignore - Re-entrancy guard, set and cleared within this call.
            $this->reading = true;
            try {
                $record = $this->provider->current();
                unset($this->warned['provider_failed']);
            } catch (Throwable $exception) {
                $this->warnOnce('provider_failed', 'Beacon credentials provider failed ({exception}); using the configured credentials.', ['exception' => $exception::class]);
            } finally {
                // @igor-ignore - Re-entrancy guard, set and cleared within this call.
                $this->reading = false;
            }
        }

        $snapshot = $this->build($record, true);
        // @igor-ignore - Process-wide credentials cache (configuration / provider values, no request data), expires by time.
        $this->current = $snapshot;
        // @igor-ignore - Cache expiry timestamp, no request data.
        $this->expiresAt = $this->seconds() + $this->cacheTtl();

        return $snapshot;
    }

    /**
     * Merge `$record` over the configuration. With `$track`, bumps the generation when any effective value changed.
     */
    private function build(?BeaconCredentials $record, bool $track): ResolvedBeaconCredentials
    {
        // @igor-ignore - Fixed set of warning codes, no request data.
        $this->warnedNow                         = [];
        [$dsn, $dsnRaw, $dsnProblem, $dsnSource] = $this->resolveDsn($record?->dsn());
        [$key, $keyRaw, $keyProblem, $keySource] = $this->resolveWorkerKey($record?->workerKey());

        $previousDsn       = $this->previousDsnOf($record, $dsn);
        $previousWorkerKey = $this->previousWorkerKeyOf($record);

        $effective = new BeaconCredentials(
            $dsnRaw,
            $keyRaw,
            $previousDsn instanceof BeaconDsn ? $record?->previousDsn() : null,
            $previousDsn instanceof BeaconDsn ? $record?->previousDsnExpiresAt() : null,
            $previousWorkerKey instanceof WorkerKey ? $record?->previousWorkerKey() : null,
            $previousWorkerKey instanceof WorkerKey ? $record?->previousWorkerKeyExpiresAt() : null,
        );

        $generation = $track ? $this->track($effective) : $this->generation;

        return new ResolvedBeaconCredentials($generation, $effective, $dsn, $dsnProblem, $dsnSource, $key, $keyProblem, $keySource, $previousDsn, $previousWorkerKey);
    }

    /**
     * @return array{0: ?BeaconDsn, 1: ?string, 2: ?string, 3: string}
     */
    private function resolveDsn(#[SensitiveParameter] ?string $fromProvider): array
    {
        if ($fromProvider !== null) {
            try {
                return [$this->parser->parse($fromProvider), $fromProvider, null, ResolvedBeaconCredentials::SOURCE_PROVIDER];
            } catch (InvalidBeaconDsnException $e) {
                $this->warnOnce('provider_dsn_invalid', 'The Beacon DSN of the credentials provider is invalid ({reason}); using the configured DSN.', ['reason' => $e->getMessage()]);
            }
        }

        $configured = (string) $this->configDsn->getValue();
        if ($configured === '') {
            return [null, null, 'No Beacon DSN: nowo_beacon.dsn (BEACON_DSN) is empty and the credentials provider has none.', ResolvedBeaconCredentials::SOURCE_NONE];
        }

        try {
            return [$this->parser->parse($configured), $configured, null, ResolvedBeaconCredentials::SOURCE_CONFIG];
        } catch (InvalidBeaconDsnException $e) {
            return [null, null, 'Invalid Beacon DSN: ' . $e->getMessage(), ResolvedBeaconCredentials::SOURCE_NONE];
        }
    }

    /**
     * @return array{0: ?WorkerKey, 1: ?string, 2: ?string, 3: string}
     */
    private function resolveWorkerKey(#[SensitiveParameter] ?string $fromProvider): array
    {
        if ($fromProvider !== null) {
            if (WorkerKey::isValid($fromProvider)) {
                return [new WorkerKey($fromProvider), $fromProvider, null, ResolvedBeaconCredentials::SOURCE_PROVIDER];
            }

            $this->warnOnce('provider_worker_key_invalid', 'The Beacon worker key of the credentials provider is not a "bwk_…" key; using the configured worker key.');
        }

        $configured = (string) $this->configWorkerKey->getValue();
        if ($configured === '') {
            return [null, null, 'nowo_beacon.messenger.remote_retry.worker_key is empty (set BEACON_WORKER_KEY) and the credentials provider has none.', ResolvedBeaconCredentials::SOURCE_NONE];
        }

        if (!WorkerKey::isValid($configured)) {
            return [null, null, 'Invalid Beacon worker key: expected "bwk_" followed by base64url characters.', ResolvedBeaconCredentials::SOURCE_NONE];
        }

        return [new WorkerKey($configured), $configured, null, ResolvedBeaconCredentials::SOURCE_CONFIG];
    }

    /**
     * The previous DSN, when it has an expiry, parses and targets the same origin and project as the current one.
     */
    private function previousDsnOf(?BeaconCredentials $record, ?BeaconDsn $current): ?BeaconDsn
    {
        $raw = $record?->previousDsn();
        if ($raw === null) {
            return null;
        }

        if (!$record?->previousDsnExpiresAt() instanceof DateTimeImmutable) {
            $this->warnOnce('previous_dsn_no_expiry', 'The previous Beacon DSN has no expiry: it is ignored.');

            return null;
        }

        try {
            $previous = $this->parser->parse($raw);
        } catch (InvalidBeaconDsnException $e) {
            $this->warnOnce('previous_dsn_invalid', 'The previous Beacon DSN is invalid ({reason}): it is ignored.', ['reason' => $e->getMessage()]);

            return null;
        }

        if (!$current instanceof BeaconDsn || $previous->getOrigin() !== $current->getOrigin() || $previous->getProjectId() !== $current->getProjectId()) {
            $this->warnOnce('previous_dsn_other_target', 'The previous Beacon DSN targets another Beacon origin or project than the current one: it is ignored.');

            return null;
        }

        return $previous;
    }

    private function previousWorkerKeyOf(?BeaconCredentials $record): ?WorkerKey
    {
        $raw = $record?->previousWorkerKey();
        if ($raw === null) {
            return null;
        }

        if (!$record?->previousWorkerKeyExpiresAt() instanceof DateTimeImmutable) {
            $this->warnOnce('previous_worker_key_no_expiry', 'The previous Beacon worker key has no expiry: it is ignored.');

            return null;
        }

        if (!WorkerKey::isValid($raw)) {
            $this->warnOnce('previous_worker_key_invalid', 'The previous Beacon worker key is not a "bwk_…" key: it is ignored.');

            return null;
        }

        return new WorkerKey($raw);
    }

    /**
     * Bump the generation when the effective values differ from the last tracked ones.
     */
    private function track(BeaconCredentials $effective): int
    {
        $fingerprint = implode("\0", [
            $effective->dsn() ?? '',
            $effective->workerKey() ?? '',
            $effective->previousDsn() ?? '',
            $effective->previousDsnExpiresAt()?->format('U') ?? '',
            $effective->previousWorkerKey() ?? '',
            $effective->previousWorkerKeyExpiresAt()?->format('U') ?? '',
        ]);

        $known = $this->fingerprint?->getValue();
        if (is_string($known) && hash_equals($known, $fingerprint)) {
            return $this->generation;
        }

        // @igor-ignore - Process-wide credentials version (configuration / provider values, no request data).
        $this->fingerprint = new SensitiveParameterValue($fingerprint);
        // @igor-ignore - Process-wide credentials version.
        ++$this->generation;
        // Values that change later may deserve their own warnings; the ones logged for these values, and a provider
        // failure streak, keep their single warning.
        $kept = $this->warnedNow;
        if (isset($this->warned['provider_failed'])) {
            $kept['provider_failed'] = true;
        }

        // @igor-ignore - Fixed set of warning codes, no request data.
        $this->warned = $kept;

        return $this->generation;
    }

    /**
     * @param array<string, scalar> $context
     */
    private function warnOnce(string $code, string $message, array $context = []): void
    {
        if (isset($this->warned[$code])) {
            return;
        }

        // @igor-ignore - Fixed set of warning codes, no request data.
        $this->warned[$code] = true;
        // @igor-ignore - Fixed set of warning codes, no request data.
        $this->warnedNow[$code] = true;
        $this->logger?->warning($message, [...$context, EnvelopeTransport::LOG_CONTEXT_FLAG => true]);
    }

    private function seconds(): float
    {
        if (!$this->clock instanceof ClockInterface) {
            return microtime(true);
        }

        return (float) $this->clock->now()->format('U.u');
    }
}
