<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use ErrorException;
use Nowo\BeaconBundle\Client\BeaconClientInterface;

use function error_get_last;
use function in_array;
use function register_shutdown_function;

use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_USER_ERROR;

/**
 * Reports fatal PHP errors that never reach kernel.exception / ConsoleEvents::ERROR.
 */
final class BeaconFatalErrorHandler
{
    private bool $registered = false;

    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Register the shutdown function once.
     */
    public function register(): void
    {
        if ($this->registered || !$this->enabled) {
            return;
        }

        $this->registered = true;
        // Intentional: fatals never hit kernel.exception / ConsoleEvents::ERROR.
        // @phpstan-ignore frankenphp.worker.noRegisterShutdownFunction
        register_shutdown_function($this->onShutdown(...));
    }

    /**
     * Capture the last fatal error when the process ends.
     */
    public function onShutdown(): void
    {
        if (!$this->enabled || !$this->client->isEnabled()) {
            return;
        }

        $this->captureFatalError(error_get_last());
    }

    /**
     * @param array{type?: int, message?: string, file?: string, line?: int}|null $error
     *
     * @internal Visible for unit tests (PHP 8.4 deprecates trigger_error E_USER_ERROR).
     */
    public function captureFatalError(?array $error): void
    {
        if ($error === null) {
            return;
        }

        if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        $message = $error['message'];
        $file    = $error['file'];
        $line    = $error['line'];

        $exception = new ErrorException($message, 0, $error['type'], $file, $line);
        $this->client->captureException($exception, [
            'fatal' => [
                'type' => $error['type'],
                'file' => $file,
                'line' => $line,
            ],
        ]);
    }
}
