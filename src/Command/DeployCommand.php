<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Command;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\DeployCapableInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function trim;

/**
 * Records a deploy marker in Symfony Beacon (run it from your deploy pipeline after the release is live).
 *
 * Beacon shows deploys next to releases / analytics and flags issues first seen shortly after a deploy.
 */
#[AsCommand(
    name: 'nowo:beacon:deploy',
    description: 'Send a deploy marker (release, environment, commit) to Symfony Beacon',
)]
final class DeployCommand extends Command
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly ?string $defaultRelease = null,
        private readonly ?string $defaultEnvironment = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('release', 'r', InputOption::VALUE_REQUIRED, 'Release / version (defaults to nowo_beacon.release)')
            ->addOption('environment', null, InputOption::VALUE_REQUIRED, 'Environment (defaults to nowo_beacon.environment; not -e, which is the kernel env)')
            ->addOption('commit', null, InputOption::VALUE_REQUIRED, 'Commit SHA')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Link to the deploy / pipeline run')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Human label (e.g. "Deploy #128")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $release = $this->option($input, 'release') ?? $this->nonEmpty($this->defaultRelease);
        if ($release === null) {
            $io->error('No release: pass --release or configure nowo_beacon.release.');

            return Command::FAILURE;
        }

        if (!$this->client instanceof DeployCapableInterface || !$this->client->isEnabled()) {
            $io->warning('Beacon reporting is disabled (empty BEACON_DSN or custom client): deploy not sent.');

            return Command::SUCCESS;
        }

        $eventId = $this->client->captureDeploy([
            'release'     => $release,
            'environment' => $this->option($input, 'environment') ?? $this->nonEmpty($this->defaultEnvironment),
            'commit'      => $this->option($input, 'commit'),
            'url'         => $this->option($input, 'url'),
            'name'        => $this->option($input, 'name'),
        ]);

        $io->success('Deploy of ' . $release . ' sent' . ($eventId !== null ? ' (event ' . $eventId . ').' : '.'));

        return Command::SUCCESS;
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $this->nonEmpty($value) : null;
    }

    private function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
