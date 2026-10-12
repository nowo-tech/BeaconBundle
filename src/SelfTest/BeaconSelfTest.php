<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\SelfTest;

use InvalidArgumentException;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Envelope\PendingTransportRegistry;
use Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Throwable;

use function bin2hex;
use function implode;
use function in_array;
use function intdiv;
use function is_string;
use function random_bytes;
use function sprintf;
use function strlen;
use function trigger_error;
use function trim;

use const E_USER_WARNING;

/**
 * Produces REAL failures inside the host application so the bundle's own listeners
 * (kernel.exception, console error, PHP warning handler, Messenger failure listener)
 * capture and ship them to the configured Beacon project — no hand-built Envelopes.
 *
 * Uncaught kinds are thrown by {@see run()} and must not be caught by the caller;
 * returning kinds report through the client and return a {@see SelfTestResult}.
 * Every event of the run is tagged `beacon_selftest=<run token>` and `beacon_selftest_kind=<kind>`.
 */
final readonly class BeaconSelfTest
{
    /** Uncaught exception (HTTP: kernel.exception listener; console: console error listener). */
    public const KIND_UNCAUGHT_EXCEPTION = 'uncaught-exception';

    /** Uncaught engine \Error (DivisionByZeroError). */
    public const KIND_PHP_ERROR = 'php-error';

    /** E_USER_WARNING through the bundle PHP warning handler; execution continues. */
    public const KIND_PHP_WARNING = 'php-warning';

    /** Handled exception passed to captureException(). */
    public const KIND_CAPTURED_EXCEPTION = 'captured-exception';

    /** captureMessage() at warning level. */
    public const KIND_CAPTURED_MESSAGE = 'captured-message';

    /** Dispatch {@see BeaconSelfTestFailMessage}; its handler always throws (requires symfony/messenger). */
    public const KIND_MESSENGER_FAILURE = 'messenger-failure';

    /** @var list<string> */
    public const UNCAUGHT_KINDS = [
        self::KIND_UNCAUGHT_EXCEPTION,
        self::KIND_PHP_ERROR,
    ];

    /** @var list<string> */
    public const RETURNING_KINDS = [
        self::KIND_PHP_WARNING,
        self::KIND_CAPTURED_EXCEPTION,
        self::KIND_CAPTURED_MESSAGE,
        self::KIND_MESSENGER_FAILURE,
    ];

    /** Scope tag carrying the run token (filter Issues by `beacon_selftest:<run>`). */
    public const TAG = 'beacon_selftest';

    /** Scope tag carrying the kind. */
    public const TAG_KIND = 'beacon_selftest_kind';

    public function __construct(
        private BeaconClientInterface $client,
        private BeaconDsnParser $parser,
        private mixed $dsn,
        private bool $reportingEnabled = true,
        private ?MessageBusInterface $bus = null,
        private ?PendingTransportRegistry $pendingTransports = null,
        private bool $exceptionListener = true,
        private bool $consoleListener = true,
        private bool $phpWarningHandler = true,
        private bool $messengerListener = true,
    ) {
    }

    /**
     * All kinds (uncaught first).
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [...self::UNCAUGHT_KINDS, ...self::RETURNING_KINDS];
    }

    /**
     * Whether `$kind` aborts the current request / command when run.
     */
    public static function isUncaught(string $kind): bool
    {
        return in_array($kind, self::UNCAUGHT_KINDS, true);
    }

    /**
     * Short random token identifying one self-test run.
     */
    public static function newRunToken(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * Whether a Messenger bus is wired (the messenger-failure kind is available).
     */
    public function isMessengerAvailable(): bool
    {
        return $this->bus instanceof MessageBusInterface;
    }

    /**
     * Configuration snapshot for an admin page / CLI (never exposes keys).
     *
     * @return array{
     *     enabled: bool,
     *     origin: string|null,
     *     project_id: string|null,
     *     error: string|null,
     *     listeners: array{exception: bool, console: bool, php_warning: bool, messenger: bool},
     *     messenger_bus: bool
     * }
     */
    public function status(): array
    {
        $status = [
            'enabled'    => $this->client->isEnabled(),
            'origin'     => null,
            'project_id' => null,
            'error'      => null,
            'listeners'  => [
                'exception'   => $this->exceptionListener,
                'console'     => $this->consoleListener,
                'php_warning' => $this->phpWarningHandler,
                'messenger'   => $this->messengerListener,
            ],
            'messenger_bus' => $this->isMessengerAvailable(),
        ];

        $dsn = $this->dsnString();
        if ($dsn === '') {
            return $status;
        }

        try {
            $parsed               = $this->parser->parse($dsn);
            $status['origin']     = $parsed->getOrigin();
            $status['project_id'] = $parsed->getProjectId();
        } catch (InvalidBeaconDsnException $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * Fail with a clear message when events of this run could not reach Beacon.
     *
     * @throws SelfTestUnavailableException
     */
    public function assertReady(): void
    {
        if (!$this->reportingEnabled) {
            throw new SelfTestUnavailableException('Beacon reporting is disabled (nowo_beacon.enabled: false).');
        }

        $dsn = $this->dsnString();
        if ($dsn === '') {
            throw new SelfTestUnavailableException('No Beacon DSN configured: set BEACON_DSN (nowo_beacon.dsn).');
        }

        try {
            $this->parser->parse($dsn);
        } catch (InvalidBeaconDsnException $e) {
            throw new SelfTestUnavailableException('Invalid Beacon DSN: ' . $e->getMessage(), 0, $e);
        }

        if (!$this->client->isEnabled()) {
            throw new SelfTestUnavailableException('The Beacon client is disabled; check nowo_beacon.enabled and BEACON_DSN.');
        }
    }

    /**
     * Run one kind.
     *
     * Uncaught kinds throw ({@see BeaconSelfTestException} or a DivisionByZeroError) — let them
     * propagate so the bundle's exception / console listener reports them.
     *
     * @throws SelfTestUnavailableException When not ready or the kind's prerequisite is missing
     * @throws InvalidArgumentException For unknown kinds
     */
    public function run(string $kind, ?string $runToken = null): SelfTestResult
    {
        if (!in_array($kind, self::kinds(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown Beacon self-test kind "%s" (expected one of: %s).', $kind, implode(', ', self::kinds())));
        }

        $this->assertReady();
        $this->assertKindAvailable($kind);

        $runToken = $runToken !== null && trim($runToken) !== '' ? trim($runToken) : self::newRunToken();
        $this->tagRun($kind, $runToken);

        if (self::isUncaught($kind)) {
            $this->throwUncaught($kind, $runToken);
        }

        return match ($kind) {
            self::KIND_PHP_WARNING        => $this->phpWarning($runToken),
            self::KIND_CAPTURED_EXCEPTION => $this->capturedException($runToken),
            self::KIND_CAPTURED_MESSAGE   => new SelfTestResult(
                $kind,
                $runToken,
                $this->client->captureMessage(
                    sprintf('Beacon self-test: captured message (run %s)', $runToken),
                    'warning',
                    ['beacon_selftest' => ['kind' => $kind, 'run' => $runToken]],
                ),
                SelfTestResult::DELIVERY_SENT,
            ),
            default => $this->messengerFailure($runToken),
        };
    }

    /**
     * Drain pending async Envelope POSTs (no-op for the sync transport).
     */
    public function flush(): void
    {
        $this->pendingTransports?->flush();
    }

    /**
     * @throws SelfTestUnavailableException
     */
    private function assertKindAvailable(string $kind): void
    {
        if ($kind === self::KIND_PHP_WARNING && !$this->phpWarningHandler) {
            throw new SelfTestUnavailableException('The php-warning kind requires nowo_beacon.register_php_warning_handler: true.');
        }

        if ($kind === self::KIND_MESSENGER_FAILURE && !$this->isMessengerAvailable()) {
            throw new SelfTestUnavailableException('The messenger-failure kind requires symfony/messenger with a configured bus (framework.messenger).');
        }
    }

    private function tagRun(string $kind, string $runToken): void
    {
        $this->client->setTags([self::TAG => $runToken, self::TAG_KIND => $kind]); // @igor-ignore - Scope is reset per request (BeaconRequestScopeResetListener / kernel.reset).
    }

    private function throwUncaught(string $kind, string $runToken): never
    {
        if ($kind === self::KIND_PHP_ERROR) {
            // Real engine \Error (DivisionByZeroError), not a userland exception.
            $divisor = strlen($runToken) - strlen($runToken);
            intdiv(1, $divisor);
        }

        throw new BeaconSelfTestException(sprintf('Beacon self-test: uncaught exception (run %s)', $runToken));
    }

    private function phpWarning(string $runToken): SelfTestResult
    {
        // Goes through BeaconPhpWarningHandler (continue mode); execution continues.
        trigger_error(sprintf('Beacon self-test: PHP warning (run %s)', $runToken), E_USER_WARNING);

        return new SelfTestResult(self::KIND_PHP_WARNING, $runToken, null, SelfTestResult::DELIVERY_WARNING_HANDLER);
    }

    private function capturedException(string $runToken): SelfTestResult
    {
        try {
            throw new BeaconSelfTestException(sprintf('Beacon self-test: handled exception (run %s)', $runToken));
        } catch (BeaconSelfTestException $e) {
            $eventId = $this->client->captureException($e, ['beacon_selftest' => ['kind' => self::KIND_CAPTURED_EXCEPTION, 'run' => $runToken]]);
        }

        return new SelfTestResult(self::KIND_CAPTURED_EXCEPTION, $runToken, $eventId, SelfTestResult::DELIVERY_SENT);
    }

    private function messengerFailure(string $runToken): SelfTestResult
    {
        /** @var MessageBusInterface $bus checked by assertKindAvailable() */
        $bus = $this->bus;

        try {
            $envelope = $bus->dispatch(new BeaconSelfTestFailMessage($runToken));
        } catch (Throwable $e) {
            // Synchronous handling (message not routed to a transport): no worker event fires,
            // so capture the real handler failure inline.
            $eventId = $this->client->captureException($e, [
                'beacon_selftest' => ['kind' => self::KIND_MESSENGER_FAILURE, 'run' => $runToken],
                'messenger'       => ['message_class' => BeaconSelfTestFailMessage::class, 'sync' => true],
            ]);

            return new SelfTestResult(self::KIND_MESSENGER_FAILURE, $runToken, $eventId, SelfTestResult::DELIVERY_INLINE);
        }

        $delivery = $envelope->last(SentStamp::class) instanceof SentStamp
            ? SelfTestResult::DELIVERY_QUEUED
            : SelfTestResult::DELIVERY_INLINE;

        return new SelfTestResult(self::KIND_MESSENGER_FAILURE, $runToken, null, $delivery);
    }

    private function dsnString(): string
    {
        return is_string($this->dsn) ? trim($this->dsn) : '';
    }
}
