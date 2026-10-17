<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scheduled nightly command (cron `0 3 * * *`, Europe/Madrid) — Beacon receives only its name.
 */
#[AsCommand(
    name: 'app:demo-nightly-report',
    description: 'Demo nightly report scheduled through Symfony Scheduler',
)]
final class DemoNightlyReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Report window start', 'yesterday');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Nightly report since ' . (string) $input->getOption('since'));

        return Command::SUCCESS;
    }
}
