<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

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
 */
final class BeaconPhpWarningHandler
{
    /** Default: warnings + notices (not deprecations). */
    public const int DEFAULT_LEVELS = E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE
        | E_CORE_WARNING | E_COMPILE_WARNING;

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
     * Register the error handler once (newest handler runs first).
     */
    public function register(): void
    {
        if ($this->registered || !$this->enabled || $this->levels === 0) {
            return;
        }

        // @igor-ignore - Process-local once-per-worker flag (same pattern as BeaconFatalErrorHandler).
        $this->registered = true;
        // Intentional: must observe PHP warnings before Symfony converts them; restored via unregister().
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        $this->previous = set_error_handler($this->handle(...)); // @igor-ignore - Process-local previous-handler pointer.
    }

    /**
     * Restore the previous handler (tests / explicit teardown).
     *
     * @internal
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        restore_error_handler();
        // @igor-ignore - Test/teardown only; clears process-local registration flags.
        $this->registered = false;
        // @igor-ignore - Test/teardown only; clears process-local previous-handler pointer.
        $this->previous = null;
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
