<?php

declare(strict_types=1);

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Throws so BeaconConsoleErrorListener can report an uncaught console error.
 *
 * Run: php bin/console app:demo-console-boom
 */
#[AsCommand(
    name: 'app:demo-console-boom',
    description: 'Throw an uncaught exception for the Beacon console error listener',
)]
final class DemoConsoleBoomCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new RuntimeException('Beacon demo console boom (register_console_listener).');
    }
}
