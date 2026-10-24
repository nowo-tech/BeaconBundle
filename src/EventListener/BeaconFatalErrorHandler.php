<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use ErrorException;
use Nowo\BeaconBundle\Client\BeaconClientInterface;

use function error_get_last;
use function in_array;
use function is_int;
use function register_shutdown_function;

use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_USER_ERROR;

/**
 * Reports fatal PHP errors that never reach kernel.exception / ConsoleEvents::ERROR.
 *
 * One shutdown function per process forwards to the handler of the kernel booted last: PHP keeps shutdown functions
 * until the process ends, so registering `$this` on every boot kept each kernel's container alive (PHPUnit, any
 * process that boots many kernels). {@see unregister()} (bundle shutdown) releases it.
 */
final class BeaconFatalErrorHandler
{
    /** The handler of the kernel booted last (null after its kernel shut down). */
    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
    private static ?self $active = null;

    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
    private static bool $shutdownFunctionRegistered = false;

    private bool $registered = false;

    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Make this handler the active one; the shutdown function is registered once per process.
     */
    public function register(): void
    {
        if ($this->registered || !$this->enabled) {
            return;
        }

        // @igor-ignore - Not shared worker service state.
        $this->registered = true;
        // @igor-ignore - Process-wide pointer to the current kernel's handler, cleared by unregister().
        // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
        self::$active = $this;
        if (self::$shutdownFunctionRegistered) {
            return;
        }

        // @igor-ignore - Process-local once-per-process flag.
        // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
        self::$shutdownFunctionRegistered = true;
        // Intentional: fatals never hit kernel.exception / ConsoleEvents::ERROR. Static closure: retains no handler.
        // @phpstan-ignore frankenphp.worker.noRegisterShutdownFunction
        register_shutdown_function(self::dispatchShutdown(...));
    }

    /**
     * Release this handler (kernel shutdown) so its client and container can be freed.
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        // @igor-ignore - Kernel shutdown; clears the registration flag.
        $this->registered = false;
        if (self::$active === $this) {
            // @igor-ignore - Kernel shutdown; drops the process-wide pointer.
            // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
            self::$active = null;
        }
    }

    /**
     * The process shutdown function: forwards to the active handler, if any.
     *
     * @internal
     */
    public static function dispatchShutdown(): void
    {
        self::$active?->onShutdown();
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

        $type = $error['type'] ?? null;
        if (!is_int($type) || !in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        $message = $error['message'] ?? '';
        $file    = $error['file'] ?? '';
        $line    = $error['line'] ?? 0;

        $exception = new ErrorException($message, 0, $type, $file, $line);
        $this->client->captureException($exception, [
            'fatal' => [
                'type' => $type,
                'file' => $file,
                'line' => $line,
            ],
        ]);
    }
}
