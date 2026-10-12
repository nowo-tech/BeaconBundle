<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Command;

use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\SelfTest\SelfTestResult;
use Nowo\BeaconBundle\SelfTest\SelfTestUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function is_string;
use function sprintf;

/**
 * Triggers real failures so the bundle's own listeners report them to the configured Beacon project.
 */
#[AsCommand(
    name: 'nowo:beacon:self-test',
    description: 'Trigger a real failure (uncaught exception, PHP error/warning, captured event, Messenger failure) reported by BeaconBundle',
)]
final class SelfTestCommand extends Command
{
    /** Runs every returning kind (messenger-failure only when a bus is available). */
    public const TYPE_ALL = 'all';

    private const DESCRIPTIONS = [
        BeaconSelfTest::KIND_UNCAUGHT_EXCEPTION => 'Throw an uncaught exception (console error listener; command exits 1)',
        BeaconSelfTest::KIND_PHP_ERROR          => 'Throw an uncaught engine Error, DivisionByZeroError (console error listener; command exits 1)',
        BeaconSelfTest::KIND_PHP_WARNING        => 'Trigger E_USER_WARNING through the PHP warning handler (command continues)',
        BeaconSelfTest::KIND_CAPTURED_EXCEPTION => 'captureException() of a handled exception',
        BeaconSelfTest::KIND_CAPTURED_MESSAGE   => 'captureMessage() at warning level',
        BeaconSelfTest::KIND_MESSENGER_FAILURE  => 'Dispatch a message whose handler throws (needs a worker for async transports)',
        self::TYPE_ALL                          => 'Every returning kind (php-warning, captured-*, messenger-failure when available)',
    ];

    public function __construct(
        private readonly BeaconSelfTest $selfTest,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'type',
                InputArgument::OPTIONAL,
                'Failure kind: ' . implode(', ', [...BeaconSelfTest::kinds(), self::TYPE_ALL]),
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'Alias of the "type" argument',
            )
            ->addOption(
                'run',
                null,
                InputOption::VALUE_REQUIRED,
                'Run token used for the beacon_selftest tag (random when omitted)',
            )
            ->setHelp(<<<'HELP'
                Produces REAL failures captured by BeaconBundle's own listeners (no hand-built Envelopes).
                Every event is tagged <info>beacon_selftest=<run></info> and <info>beacon_selftest_kind=<kind></info>.

                  <info>php %command.full_name% captured-exception</info>
                  <info>php %command.full_name% all</info>
                  <info>php %command.full_name% uncaught-exception</info>   (exits 1 by design)

                messenger-failure dispatches Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage.
                Route it to an async transport and run a worker (messenger:consume) so the Messenger failure
                listener reports it; unrouted (sync) messages are captured inline by this command.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $type = $this->resolveType($input);

        if ($type === null || !isset(self::DESCRIPTIONS[$type])) {
            if ($type !== null) {
                $io->error(sprintf('Unknown self-test type "%s".', $type));
            }
            $rows = [];
            foreach (self::DESCRIPTIONS as $kind => $description) {
                $rows[] = [$kind, $description];
            }
            $io->table(['Type', 'What happens'], $rows);

            return Command::INVALID;
        }

        try {
            $this->selfTest->assertReady();
        } catch (SelfTestUnavailableException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $status = $this->selfTest->status();
        $io->definitionList(
            ['Origin' => (string) $status['origin']],
            ['Project' => (string) $status['project_id']],
        );

        $runOption = $input->getOption('run');
        $runToken  = is_string($runOption) && $runOption !== '' ? $runOption : BeaconSelfTest::newRunToken();

        if (BeaconSelfTest::isUncaught($type)) {
            if (!$status['listeners']['console']) {
                $io->warning('nowo_beacon.register_console_listener is false: this failure will not be reported.');
            }
            $io->note(sprintf('Throwing %s (run %s). The command exits with an error by design.', $type, $runToken));

            // Throws: Symfony Console dispatches ConsoleEvents::ERROR → BeaconConsoleErrorListener,
            // then ConsoleEvents::TERMINATE flushes async transports.
            $this->selfTest->run($type, $runToken);
        }

        $kinds = $type === self::TYPE_ALL ? BeaconSelfTest::RETURNING_KINDS : [$type];
        $rows  = [];
        $exit  = Command::SUCCESS;

        foreach ($kinds as $kind) {
            if ($type === self::TYPE_ALL && $kind === BeaconSelfTest::KIND_MESSENGER_FAILURE && !$this->selfTest->isMessengerAvailable()) {
                $rows[] = [$kind, '—', 'skipped (no Messenger bus)'];

                continue;
            }

            try {
                $result = $this->selfTest->run($kind, $runToken);
                $rows[] = [$kind, $result->eventId ?? '—', $result->delivery];
                if ($result->delivery === SelfTestResult::DELIVERY_QUEUED) {
                    $io->note('BeaconSelfTestFailMessage was queued: run a worker (messenger:consume) so the failure is reported.');
                }
            } catch (SelfTestUnavailableException $e) {
                $rows[] = [$kind, '—', 'unavailable: ' . $e->getMessage()];
                $exit   = Command::FAILURE;
            }
        }

        $this->selfTest->flush();

        $io->table(['Kind', 'Event id', 'Delivery'], $rows);

        if ($exit !== Command::SUCCESS) {
            $io->error('Some self-test kinds could not run.');

            return $exit;
        }

        $io->success(sprintf('Self-test sent. Filter Beacon Issues by tag %s:%s.', BeaconSelfTest::TAG, $runToken));

        return Command::SUCCESS;
    }

    private function resolveType(InputInterface $input): ?string
    {
        foreach ([$input->getArgument('type'), $input->getOption('type')] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
