<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Command;

use Nowo\BeaconBundle\Connection\BeaconConnectionTester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console probe for Symfony Beacon connectivity using the configured DSN.
 */
#[AsCommand(
    name: 'nowo:beacon:test',
    description: 'Test connectivity to Symfony Beacon using the configured BEACON_DSN',
)]
final class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly BeaconConnectionTester $tester,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'check-only',
                null,
                InputOption::VALUE_NONE,
                'Validate and display the DSN target without sending an Envelope',
            )
            ->addOption(
                'message',
                'm',
                InputOption::VALUE_REQUIRED,
                'Message body for the test event',
                'BeaconBundle connection test',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $checkOnly = (bool) $input->getOption('check-only');
        $message   = (string) $input->getOption('message');
        $result    = $this->tester->test($checkOnly, $message);

        $target = $result->getTarget();
        if ($target !== []) {
            $io->definitionList(
                ['Origin' => $target['origin'] ?? ''],
                ['Project'           => $target['project_id'] ?? ''],
                ['Public key'        => $target['public_key'] ?? ''],
                ['Envelope URL'      => $target['envelope_url'] ?? ''],
                ['Reporting enabled' => ($target['reporting_enabled'] ?? false) ? 'yes' : 'no'],
            );
        }

        if ($result->getEventId() !== null) {
            $io->writeln('Event id: <info>' . $result->getEventId() . '</info>');
        }

        if ($result->getHttpStatus() !== null) {
            $io->writeln('HTTP status: <info>' . $result->getHttpStatus() . '</info>');
        }

        if ($result->isSuccess()) {
            $io->success($result->getMessage());

            return Command::SUCCESS;
        }

        $io->error($result->getMessage());

        return Command::FAILURE;
    }
}
