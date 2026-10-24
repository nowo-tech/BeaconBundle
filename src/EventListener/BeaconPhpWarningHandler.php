<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Closure;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Throwable;

use function error_reporting;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;
use function strtolower;

use const E_COMPILE_WARNING;
use const E_CORE_WARNING;
use const E_DEPRECATED;
use const E_NOTICE;
use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;

/**
 * Captures PHP warnings/notices as Beacon events without converting them into exceptions.
 *
 * When {@see $continue} is true (default), handled levels are reported and the request
 * keeps running even if Symfony's ErrorHandler would otherwise throw in debug mode.
 *
 * One error handler per process forwards to the handler of the kernel booted last: pushing `$this` on every boot
 * kept each kernel's container alive in the error-handler stack. {@see unregister()} (bundle shutdown) releases it.
 */
final class BeaconPhpWarningHandler
{
    /** Default: warnings + notices (not deprecations). Untyped for PHP 8.2 (typed consts need 8.3+). */
    public const DEFAULT_LEVELS = E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE
        | E_CORE_WARNING | E_COMPILE_WARNING;

    /** The handler of the kernel booted last (null after its kernel shut down). */
    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
    private static ?self $active = null;

    /** The process-wide error handler installed by {@see register()} (null when not installed). */
    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
    private static ?Closure $installed = null;

    /** @var (callable(int, string, string, int): bool)|null the handler that was current before ours was installed */
    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (process-wide hook state, cleared by unregister())
    private static $processPrevious;

    private bool $registered = false;

    private bool $handling = false;

    /** @var (callable(int, string, string, int): bool)|null */
    private $previous;

    public function __construct(
        private readonly BeaconClientInterface $client,
        private readonly bool $enabled = true,
        private readonly int $levels = self::DEFAULT_LEVELS,
        private readonly bool $continue = true,
        private readonly bool $captureSilenced = false,
    ) {
    }

    /**
     * Make this handler the active one; the process-wide error handler is installed once (newest handler runs first).
     */
    public function register(): void
    {
        if ($this->registered || !$this->enabled || $this->levels === 0) {
            return;
        }

        // @igor-ignore - Process-local once-per-worker flag (same pattern as BeaconFatalErrorHandler).
        $this->registered = true;
        if (!self::$installed instanceof Closure) {
            // Static closure: the error-handler stack retains no handler instance.
            // @igor-ignore - Process-local installed-handler pointer, cleared by unregister().
            self::$installed = self::dispatch(...); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
            // Intentional: must observe PHP warnings before Symfony converts them; restored via unregister().
            // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler, frankenphp.worker.noMutableStaticProperty
            self::$processPrevious = set_error_handler(self::$installed); // @igor-ignore - Process-local previous-handler pointer.
        }

        // @igor-ignore - Process-local previous-handler pointer.
        $this->previous = self::$processPrevious;
        // @igor-ignore - Process-wide pointer to the current kernel's handler, cleared by unregister().
        self::$active = $this; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * Release this handler (kernel shutdown); the process-wide error handler is removed when it is still on top of the
     * stack (another handler pushed later stays in place, ours then only forwards to the previous one).
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        // @igor-ignore - Kernel shutdown; clears process-local registration flags.
        $this->registered = false;
        // @igor-ignore - Kernel shutdown; clears the previous-handler pointer.
        $this->previous = null;
        if (self::$active !== $this) {
            return;
        }

        // @igor-ignore - Kernel shutdown; drops the process-wide pointer.
        self::$active = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // Peek the top of the stack: set_error_handler() returns the current handler.
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        $top = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        if ($top !== self::$installed) {
            return;
        }

        restore_error_handler();
        // @igor-ignore - Kernel shutdown; the process-wide handler is uninstalled.
        self::$installed = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - Kernel shutdown; clears the previous-handler pointer.
        self::$processPrevious = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * The process-wide error handler: forwards to the active handler, else to the previous one.
     *
     * @internal
     */
    public static function dispatch(int $type, string $message, string $file, int $line): bool
    {
        if (self::$active instanceof self) {
            return self::$active->handle($type, $message, $file, $line);
        }

        if (self::$processPrevious !== null) {
            return (bool) (self::$processPrevious)($type, $message, $file, $line);
        }

        return false;
    }

    /**
     * @internal visible for unit tests
     */
    public function handle(int $type, string $message, string $file, int $line): bool
    {
        // Read-only probe of the current mask (no level argument); needed to skip @-silenced errors.
        // @igor-ignore - error_reporting() without args does not mutate process state.
        $silenced      = 0 === (error_reporting() & $type);
        $matchesLevel  = 0 !== ($type & $this->levels);
        $shouldCapture = $matchesLevel
            && (!$silenced || $this->captureSilenced)
            && $this->client->isEnabled()
            && !$this->handling;

        if ($shouldCapture) {
            $this->handling = true;
            try {
                $this->capture($type, $message, $file, $line);
            } catch (Throwable) {
                // Never let reporting break the application flow.
            } finally {
                $this->handling = false;
            }
        }

        // Swallow handled levels so the request continues (no ErrorException from Symfony).
        if ($this->continue && $matchesLevel && (!$silenced || $this->captureSilenced) && $this->client->isEnabled()) {
            return true;
        }

        if ($this->previous !== null) {
            return (bool) ($this->previous)($type, $message, $file, $line);
        }

        return false;
    }

    /**
     * @internal visible for unit tests
     */
    public function capture(int $type, string $message, string $file, int $line): void
    {
        $level = $this->mapBeaconLevel($type);
        $label = $this->phpLevelName($type);

        $this->client->captureMessage(
            $label . ': ' . $message,
            $level,
            [
                'php_error' => [
                    'type'    => $type,
                    'name'    => $label,
                    'file'    => $file,
                    'line'    => $line,
                    'message' => $message,
                ],
            ],
            [
                'php-warning',
                $label,
                $message,
                $file . ':' . $line,
            ],
        );
    }

    private function mapBeaconLevel(int $type): string
    {
        if (0 !== ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING))) {
            return 'warning';
        }

        return 'info';
    }

    private function phpLevelName(int $type): string
    {
        return match (true) {
            0 !== ($type & E_USER_WARNING)    => 'User Warning',
            0 !== ($type & E_WARNING)         => 'Warning',
            0 !== ($type & E_USER_NOTICE)     => 'User Notice',
            0 !== ($type & E_NOTICE)          => 'Notice',
            0 !== ($type & E_USER_DEPRECATED) => 'User Deprecated',
            0 !== ($type & E_DEPRECATED)      => 'Deprecated',
            0 !== ($type & E_CORE_WARNING)    => 'Core Warning',
            0 !== ($type & E_COMPILE_WARNING) => 'Compile Warning',
            default                           => 'Warning',
        };
    }

    /**
     * Resolve named PHP error levels from config into a bit field.
     *
     * @param list<string> $names
     */
    public static function levelsFromNames(array $names): int
    {
        $map = [
            'warning'         => E_WARNING,
            'user_warning'    => E_USER_WARNING,
            'notice'          => E_NOTICE,
            'user_notice'     => E_USER_NOTICE,
            'deprecated'      => E_DEPRECATED,
            'user_deprecated' => E_USER_DEPRECATED,
            'core_warning'    => E_CORE_WARNING,
            'compile_warning' => E_COMPILE_WARNING,
        ];

        $bits = 0;
        foreach ($names as $name) {
            $key = strtolower(str_replace(['-', ' '], '_', $name));
            if (isset($map[$key])) {
                $bits |= $map[$key];
            }
        }

        return $bits;
    }
}
