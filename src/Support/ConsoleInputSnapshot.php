<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Support;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function getcwd;
use function in_array;
use function is_string;

/**
 * Builds a safe snapshot of console input for Beacon extras (no raw argv).
 *
 * @phpstan-type ConsoleInputExtra array{
 *     interactive: bool,
 *     arguments?: array<string, mixed>,
 *     options?: array<string, mixed>,
 *     missing_arguments?: list<string>
 * }
 */
final class ConsoleInputSnapshot
{
    /**
     * @return ConsoleInputExtra
     */
    public static function from(InputInterface $input, ?Command $command): array
    {
        $snapshot = [
            'interactive' => $input->isInteractive(),
        ];

        $arguments = self::arguments($input, $command);
        if ($arguments !== []) {
            $snapshot['arguments'] = SensitiveValueRedactor::redactMap($arguments);
        }

        $options = self::options($input, $command);
        if ($options !== []) {
            $snapshot['options'] = SensitiveValueRedactor::redactMap($options);
        }

        $missing = self::missingArguments($input, $command);
        if ($missing !== []) {
            $snapshot['missing_arguments'] = $missing;
        }

        return $snapshot;
    }

    /**
     * @return array{
     *     command_class?: class-string,
     *     verbosity?: int,
     *     cwd?: string
     * }
     */
    public static function runtime(?Command $command, ?OutputInterface $output = null): array
    {
        $runtime = [];
        if ($command instanceof Command) {
            $runtime['command_class'] = $command::class;
        }
        if ($output instanceof OutputInterface) {
            $runtime['verbosity'] = $output->getVerbosity();
        }
        $cwd = getcwd();
        if (is_string($cwd)) {
            $runtime['cwd'] = $cwd;
        }

        return $runtime;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arguments(InputInterface $input, ?Command $command): array
    {
        try {
            $bound = $input->getArguments();
            if ($bound !== []) {
                return $bound;
            }
        } catch (Throwable) {
            // Input may be unbound when the error happens very early.
        }

        if (!$command instanceof Command) {
            return [];
        }

        $out = [];
        foreach ($command->getDefinition()->getArguments() as $argument) {
            $name = $argument->getName();
            try {
                $out[$name] = $input->hasArgument($name)
                    ? $input->getArgument($name)
                    : $argument->getDefault();
            } catch (Throwable) {
                $out[$name] = null;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function missingArguments(InputInterface $input, ?Command $command): array
    {
        if (!$command instanceof Command) {
            return [];
        }

        $missing = [];
        foreach ($command->getDefinition()->getArguments() as $argument) {
            if (!$argument->isRequired()) {
                continue;
            }
            $name = $argument->getName();
            try {
                if (!$input->hasArgument($name)) {
                    $missing[] = $name;

                    continue;
                }
                $value = $input->getArgument($name);
                if (in_array($value, [null, '', []], true)) {
                    $missing[] = $name;
                }
            } catch (Throwable) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(InputInterface $input, ?Command $command): array
    {
        try {
            $bound = $input->getOptions();
            if ($bound !== []) {
                return $bound;
            }
        } catch (Throwable) {
            // unbound
        }

        if (!$command instanceof Command) {
            return [];
        }

        $out = [];
        foreach ($command->getDefinition()->getOptions() as $option) {
            $name = $option->getName();
            try {
                if ($input->hasOption($name)) {
                    $out[$name] = $input->getOption($name);
                }
            } catch (Throwable) {
                // skip
            }
        }

        return $out;
    }
}
