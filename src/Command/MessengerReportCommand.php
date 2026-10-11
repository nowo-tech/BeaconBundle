<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Command;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\QueueSnapshotCapableInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Throwable;

use function array_keys;
use function array_map;
use function in_array;
use function is_string;
use function sort;
use function str_starts_with;

/**
 * Sends a Messenger queue snapshot (message count per transport) to Beacon.
 *
 * Schedule it (cron, Symfony Scheduler, systemd timer…) every few minutes. Beacon keeps the latest
 * count per transport and opens an issue when a failure transport reaches the project threshold.
 */
#[AsCommand(
    name: 'nowo:beacon:messenger-report',
    description: 'Send Messenger message counts per transport (queue snapshot) to Symfony Beacon',
)]
final class MessengerReportCommand extends Command
{
    /**
     * @param ServiceLocator<mixed>|null $receiverLocator Messenger receivers by transport name
     * @param list<string> $failureTransports Transport names reported as failure transports
     */
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly ?ServiceLocator $receiverLocator = null,
        private readonly array $failureTransports = ['failed'],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'failure-transport',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Transport name(s) to flag as failure transports (overrides nowo_beacon.messenger.failure_transports)',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print the snapshot without sending it',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->receiverLocator instanceof ServiceLocator) {
            $io->error('symfony/messenger is not configured: no transports to report.');

            return Command::FAILURE;
        }

        /** @var list<string> $override */
        $override = $input->getOption('failure-transport');
        $failure  = $override !== [] ? $override : $this->failureTransports;

        $transports = [];
        foreach ($this->transportNames() as $name) {
            $transports[] = [
                'name'    => $name,
                'count'   => $this->count($name),
                'failure' => in_array($name, $failure, true),
            ];
        }

        if ($transports === []) {
            $io->warning('No Messenger transports found.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Transport', 'Messages', 'Failure transport'],
            array_map(
                static fn (array $t): array => [$t['name'], $t['count'] ?? 'n/a', $t['failure'] ? 'yes' : 'no'],
                $transports,
            ),
        );

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Dry run: snapshot not sent.');

            return Command::SUCCESS;
        }

        if (!$this->client instanceof QueueSnapshotCapableInterface || !$this->client->isEnabled()) {
            $io->warning('Beacon reporting is disabled (empty BEACON_DSN or custom client): snapshot not sent.');

            return Command::SUCCESS;
        }

        $eventId = $this->client->captureQueueSnapshot($transports);
        $io->success('Queue snapshot sent' . ($eventId !== null ? ' (event ' . $eventId . ').' : '.'));

        return Command::SUCCESS;
    }

    /**
     * Transport names from the Messenger receiver locator (service-id aliases are skipped).
     *
     * @return list<string>
     */
    private function transportNames(): array
    {
        $names = [];
        foreach (array_keys($this->receiverLocator?->getProvidedServices() ?? []) as $name) {
            if (is_string($name) && !str_starts_with($name, 'messenger.transport.')) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    private function count(string $name): ?int
    {
        try {
            $receiver = $this->receiverLocator?->get($name);
        } catch (Throwable) {
            return null;
        }

        if (!$receiver instanceof MessageCountAwareInterface) {
            return null;
        }

        try {
            return $receiver->getMessageCount();
        } catch (Throwable) {
            return null;
        }
    }
}
