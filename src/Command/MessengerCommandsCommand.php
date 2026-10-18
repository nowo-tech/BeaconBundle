<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Command;

use Closure;
use Nowo\BeaconBundle\Messenger\RemoteRetry\PollReport;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function hrtime;
use function is_numeric;
use function max;
use function min;
use function sprintf;
use function usleep;

/**
 * Polls Beacon for remote Messenger commands (retry / discard one failed message) outside workers.
 *
 * For apps that do not keep `messenger:consume` running (cron: `--once`) or that set
 * `messenger.remote_retry.poll_in_workers: false` to poll from one dedicated process.
 */
#[AsCommand(
    name: 'nowo:beacon:messenger-commands',
    description: 'Claim and execute remote Messenger commands (retry / discard a failed message) from Symfony Beacon',
)]
final class MessengerCommandsCommand extends Command
{
    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $monotonic;

    /**
     * @param (Closure(float): void)|null $sleep Sleeps the given seconds (tests)
     * @param (Closure(): float)|null $monotonic Monotonic seconds (tests)
     */
    public function __construct(
        private readonly ?RemoteCommandPoller $poller = null,
        ?Closure $sleep = null,
        ?Closure $monotonic = null,
    ) {
        parent::__construct();
        $this->sleep     = $sleep ?? static function (float $seconds): void { usleep((int) ($seconds * 1e6)); };
        $this->monotonic = $monotonic ?? static fn (): float => hrtime(true) / 1e9;
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Poll once and exit (cron)')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0 = no limit)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->poller instanceof RemoteCommandPoller) {
            $io->error('Remote Messenger commands are disabled: set nowo_beacon.messenger.remote_retry.enabled to true (with BEACON_DSN and BEACON_WORKER_KEY).');

            return Command::FAILURE;
        }

        $limitOption = $input->getOption('time-limit');
        if (!is_numeric($limitOption) || (float) $limitOption < 0) {
            $io->error('--time-limit must be a number of seconds (0 = no limit).');

            return Command::INVALID;
        }

        if ((bool) $input->getOption('once')) {
            $report = $this->poller->poll();
            $this->print($io, $report);

            return $report->status === PollReport::STATUS_DISABLED || $report->status === PollReport::STATUS_AUTH_ERROR
                ? Command::FAILURE
                : Command::SUCCESS;
        }

        $limit    = (float) $limitOption;
        $deadline = $limit > 0 ? ($this->monotonic)() + $limit : null;

        while (true) {
            $report = $this->poller->pollIfDue();
            if ($report instanceof PollReport) {
                $this->print($io, $report);
                if ($report->status === PollReport::STATUS_DISABLED) {
                    return Command::FAILURE;
                }
            }

            $wait = max(0.1, $this->poller->secondsUntilNextPoll());
            if ($deadline !== null) {
                $left = $deadline - ($this->monotonic)();
                if ($left <= 0) {
                    return Command::SUCCESS;
                }
                $wait = min($wait, $left);
            }

            ($this->sleep)(min($wait, 60.0));
        }
    }

    private function print(SymfonyStyle $io, PollReport $report): void
    {
        $line = sprintf('Poll: %s, next in %ds', $report->status, (int) $report->nextPollIn);
        if ($report->detail !== null) {
            $line .= ' — ' . $report->detail;
        }
        $io->writeln($line);

        if ($report->commands !== []) {
            $io->table(
                ['Command', 'Action', 'Failure transport', 'Message', 'Result', 'Reported'],
                array_map(
                    static fn (array $c): array => [$c['id'], $c['action'], $c['failure_transport'], $c['message_id'], $c['result'], $c['reported'] ? 'yes' : 'no'],
                    $report->commands,
                ),
            );
        }
    }
}
