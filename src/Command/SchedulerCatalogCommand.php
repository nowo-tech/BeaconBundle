<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Command;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\CheckInCapableInterface;
use Nowo\BeaconBundle\Scheduler\ScheduleCatalogBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_diff;
use function array_values;
use function implode;
use function is_string;
use function sprintf;

/**
 * Sends the Symfony Scheduler catalog (`schedule_catalog`) to Beacon on demand.
 *
 * Scheduler workers send it on start when `nowo_beacon.scheduler.check_ins.enabled` is true; run this
 * from deploy scripts or for apps whose scheduler worker rarely restarts.
 */
#[AsCommand(
    name: 'nowo:beacon:scheduler-catalog',
    description: 'Send the Symfony Scheduler catalog (schedules and recurring tasks) to Symfony Beacon',
)]
final class SchedulerCatalogCommand extends Command
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly ScheduleCatalogBuilder $catalogBuilder,
        private readonly bool $checkInsEnabled = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'schedule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Schedule name(s) to send (default: all schedules)',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print the catalog without sending it',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $known = $this->catalogBuilder->scheduleNames();
        if ($known === []) {
            $io->warning('No Symfony Scheduler schedules found.');

            return Command::SUCCESS;
        }

        /** @var list<string> $only */
        $only    = $input->getOption('schedule');
        $unknown = array_values(array_diff($only, $known));
        if ($unknown !== []) {
            $io->error(sprintf('Unknown schedule(s): %s. Known: %s.', implode(', ', $unknown), implode(', ', $known)));

            return Command::FAILURE;
        }

        $catalog = $this->catalogBuilder->build($only !== [] ? $only : null);

        $rows = [];
        foreach ($catalog as $schedule) {
            foreach ($schedule['entries'] as $entry) {
                $trigger = $entry['trigger'] ?? [];
                $rows[]  = [
                    $schedule['name'],
                    $entry['recurring_id'] ?? '',
                    $this->label($entry),
                    is_string($trigger['description'] ?? null) ? $trigger['description'] : '',
                    is_string($entry['next_run_at'] ?? null) ? $entry['next_run_at'] : '—',
                    isset($entry['max_runtime_seconds']) ? $entry['max_runtime_seconds'] . ' s' : '—',
                ];
            }
        }
        $io->table(['Schedule', 'Recurring id', 'Task', 'Trigger', 'Next run (UTC)', 'Max runtime'], $rows);

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Dry run: catalog not sent.');

            return Command::SUCCESS;
        }

        if (!$this->checkInsEnabled) {
            $io->warning('nowo_beacon.scheduler.check_ins.enabled is false: catalog not sent.');

            return Command::SUCCESS;
        }

        if (!$this->client instanceof CheckInCapableInterface || !$this->client->isEnabled()) {
            $io->warning('Beacon reporting is disabled (empty BEACON_DSN or custom client): catalog not sent.');

            return Command::SUCCESS;
        }

        $eventId = $this->client->captureScheduleCatalog($catalog);
        $io->success('Schedule catalog sent' . ($eventId !== null ? ' (event ' . $eventId . ').' : '.'));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function label(array $entry): string
    {
        foreach (['command', 'message_class'] as $key) {
            if (is_string($entry[$key] ?? null)) {
                return $entry[$key];
            }
        }

        return '(callback provider)';
    }
}
