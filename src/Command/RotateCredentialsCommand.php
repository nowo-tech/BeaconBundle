<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Command;

use DateTimeImmutable;
use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsStoreInterface;
use Nowo\BeaconBundle\Credentials\ResolvedBeaconCredentials;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Messenger\RemoteRetry\WorkerKey;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function ctype_digit;
use function fgets;
use function hash_equals;
use function is_resource;
use function is_string;
use function sprintf;
use function strlen;
use function substr;
use function trim;

use const STDIN;

/**
 * Rotates the Beacon DSN and / or worker key kept in the credentials store (e.g. the `beacon` instance settings
 * section).
 *
 * New values are never command-line arguments (shell history, process list): they are asked as hidden input, or read
 * one per line from standard input when the command runs non-interactively (DSN first, then the worker key). The
 * replaced values stay usable as "previous" for `--grace` seconds (DSN: one re-send after an HTTP 401; worker key:
 * command signatures accepted). The command then reads the new values back through the provider and sends a test
 * Envelope with the new DSN; on any failure it restores the old record. It never prints a secret.
 */
#[AsCommand(
    name: 'nowo:beacon:credentials:rotate',
    description: 'Rotate the stored Beacon DSN and/or worker key (previous value kept until expiry, self-test, roll back on failure)',
)]
final class RotateCredentialsCommand extends Command
{
    public const DEFAULT_GRACE = 86400;

    /** Longest accepted grace period (30 days): a replaced key must stop working. */
    public const MAX_GRACE = 2592000;

    public function __construct(
        private readonly BeaconCredentialsResolver $resolver,
        private readonly BeaconConnectionTester $tester,
        private readonly BeaconDsnParser $parser,
        private readonly ?BeaconCredentialsStoreInterface $store = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dsn', null, InputOption::VALUE_NONE, 'Rotate the DSN (the new value is asked as hidden input or read from stdin)')
            ->addOption('worker-key', null, InputOption::VALUE_NONE, 'Rotate the worker key (asked as hidden input or read from stdin)')
            ->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Seconds the replaced value stays usable (0 = none, max 2592000)', (string) self::DEFAULT_GRACE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->store instanceof BeaconCredentialsStoreInterface) {
            $io->error('No writable credentials store: set nowo_beacon.instance_settings_bridge: true, or point nowo_beacon.credentials_provider at a service implementing BeaconCredentialsStoreInterface. Credentials kept only in .env are rotated by hand (docs/USAGE.md).');

            return Command::FAILURE;
        }

        $rotateDsn = (bool) $input->getOption('dsn');
        $rotateKey = (bool) $input->getOption('worker-key');
        $grace     = $input->getOption('grace');
        if (!$rotateDsn && !$rotateKey) {
            $io->error('Pass --dsn and/or --worker-key.');

            return Command::INVALID;
        }

        if (!is_string($grace) || !ctype_digit($grace) || (int) $grace > self::MAX_GRACE) {
            $io->error(sprintf('--grace must be a number of seconds between 0 and %d.', self::MAX_GRACE));

            return Command::INVALID;
        }

        // A console command arms the resolver anyway; done here too so the command also works when called directly.
        $this->resolver->arm();
        $this->resolver->invalidate();
        $current = $this->resolver->resolve();

        try {
            $stored = $this->store->current();
        } catch (Throwable $e) {
            $io->error(sprintf('Cannot read the credentials store (%s).', $e::class));

            return Command::FAILURE;
        }

        $newDsn = $rotateDsn ? $this->readSecret($input, $io, 'New Beacon DSN') : null;
        $newKey = $rotateKey ? $this->readSecret($input, $io, 'New Beacon worker key (bwk_…)') : null;

        $problem = $this->validate($current, $newDsn, $newKey);
        if ($problem !== null) {
            $io->error($problem);

            return Command::INVALID;
        }

        $next = $this->nextRecord($current, $stored, $newDsn, $newKey, (int) $grace);

        try {
            $this->store->save($next);
        } catch (Throwable $e) {
            $io->error(sprintf('Cannot write the credentials store (%s); nothing changed.', $e::class));

            return Command::FAILURE;
        }

        $this->resolver->invalidate();
        $problem = $this->selfTest($newDsn, $newKey);
        if ($problem === null) {
            $this->describe($io);
            $io->success(sprintf(
                'Beacon credentials rotated (%s). %s Other processes pick them up within %d s (credentials_cache_ttl).',
                $rotateDsn && $rotateKey ? 'DSN and worker key' : ($rotateDsn ? 'DSN' : 'worker key'),
                (int) $grace > 0 ? sprintf('The replaced value stays usable for %d s.', (int) $grace) : 'The replaced value is no longer accepted.',
                $this->resolver->cacheTtl(),
            ));

            return Command::SUCCESS;
        }

        try {
            $this->store->save($stored ?? new BeaconCredentials());
            $this->resolver->invalidate();
            $io->error($problem . ' The previous credentials were restored.');
        } catch (Throwable $e) {
            $io->error(sprintf('%s Restoring the previous credentials FAILED (%s): restore them by hand.', $problem, $e::class));
        }

        return Command::FAILURE;
    }

    /**
     * Hidden question when interactive, else one line of standard input.
     */
    private function readSecret(InputInterface $input, SymfonyStyle $io, string $question): string
    {
        if ($input->isInteractive()) {
            $answer = $io->askHidden($question);

            return is_string($answer) ? trim($answer) : '';
        }

        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= STDIN;
        $line = is_resource($stream) ? fgets($stream) : false;

        return is_string($line) ? trim($line) : '';
    }

    private function validate(ResolvedBeaconCredentials $current, #[SensitiveParameter] ?string $newDsn, #[SensitiveParameter] ?string $newKey): ?string
    {
        if ($newDsn !== null) {
            try {
                $parsed = $this->parser->parse($newDsn);
            } catch (InvalidBeaconDsnException $e) {
                return 'Invalid new Beacon DSN: ' . $e->getMessage();
            }

            $old = $current->dsn();
            if ($old instanceof BeaconDsn && ($old->getOrigin() !== $parsed->getOrigin() || $old->getProjectId() !== $parsed->getProjectId())) {
                return 'The new DSN targets another Beacon origin or project than the current one. A rotation keeps the target; to move the project, change the stored DSN (or BEACON_DSN) instead.';
            }

            if (hash_equals($current->effective()->dsn() ?? '', $newDsn)) {
                return 'The new DSN is the current one.';
            }
        }

        if ($newKey !== null) {
            if (!WorkerKey::isValid($newKey)) {
                return 'Invalid new worker key: expected "bwk_" followed by base64url characters.';
            }

            if (hash_equals($current->effective()->workerKey() ?? '', $newKey)) {
                return 'The new worker key is the current one.';
            }
        }

        return null;
    }

    /**
     * The stored record with the new values; the effective values they replace become "previous" for `$grace` seconds.
     */
    private function nextRecord(ResolvedBeaconCredentials $current, ?BeaconCredentials $stored, #[SensitiveParameter] ?string $newDsn, #[SensitiveParameter] ?string $newKey, int $grace): BeaconCredentials
    {
        $expires = new DateTimeImmutable('@' . ($this->resolver->now()->getTimestamp() + $grace));

        [$previousDsn, $previousDsnExpiry] = $newDsn === null
            ? [$stored?->previousDsn(), $stored?->previousDsnExpiresAt()]
            : $this->previous($current->effective()->dsn(), $grace, $expires);
        [$previousKey, $previousKeyExpiry] = $newKey === null
            ? [$stored?->previousWorkerKey(), $stored?->previousWorkerKeyExpiresAt()]
            : $this->previous($current->effective()->workerKey(), $grace, $expires);

        return new BeaconCredentials(
            $newDsn ?? $stored?->dsn(),
            $newKey ?? $stored?->workerKey(),
            $previousDsn,
            $previousDsnExpiry,
            $previousKey,
            $previousKeyExpiry,
        );
    }

    /**
     * @return array{0: ?string, 1: ?DateTimeImmutable}
     */
    private function previous(#[SensitiveParameter] ?string $replaced, int $grace, DateTimeImmutable $expires): array
    {
        return $replaced === null || $grace === 0 ? [null, null] : [$replaced, $expires];
    }

    /**
     * Read the new values back through the provider, then send a test Envelope with the new DSN.
     */
    private function selfTest(#[SensitiveParameter] ?string $newDsn, #[SensitiveParameter] ?string $newKey): ?string
    {
        $resolved = $this->resolver->resolve();
        if ($newDsn !== null && ($resolved->dsnSource !== ResolvedBeaconCredentials::SOURCE_PROVIDER || !hash_equals($newDsn, $resolved->effective()->dsn() ?? ''))) {
            return 'Self-test failed: the stored DSN does not read back through the credentials provider.';
        }

        if ($newKey !== null && ($resolved->workerKeySource !== ResolvedBeaconCredentials::SOURCE_PROVIDER || !hash_equals($newKey, $resolved->effective()->workerKey() ?? ''))) {
            return 'Self-test failed: the stored worker key does not read back through the credentials provider.';
        }

        if ($newDsn !== null) {
            $result = $this->tester->testDsn($newDsn, false, 'BeaconBundle credentials rotation self-test');
            if (!$result->isSuccess()) {
                return 'Self-test failed: ' . $result->getMessage();
            }
        }

        return null;
    }

    private function describe(SymfonyStyle $io): void
    {
        $dsn = $this->resolver->resolve()->dsn();
        if (!$dsn instanceof BeaconDsn) {
            return;
        }

        $publicKey = $dsn->getPublicKey();
        $io->definitionList(
            ['Origin' => $dsn->getOrigin()],
            ['Project' => $dsn->getProjectId()],
            ['Public key' => strlen($publicKey) > 8 ? substr($publicKey, 0, 8) . '…' : $publicKey],
        );
    }
}
