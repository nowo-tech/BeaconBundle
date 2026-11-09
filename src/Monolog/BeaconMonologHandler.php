<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Support\IgnoredException;
use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use Throwable;

/**
 * Forwards Monolog records to Beacon as messages (optional; requires monolog/monolog).
 *
 * Records written by the bundle's own transport (context flag {@see EnvelopeTransport::LOG_CONTEXT_FLAG})
 * are never forwarded: they describe a failed send to Beacon, and reporting them through the same
 * transport while the receiver is down would loop forever. They still reach the other handlers.
 *
 * Records whose `context.exception` matches the shared ignore rule ({@see IgnoredException}:
 * `nowo_beacon.ignore_exceptions` + `monolog_handler.ignore_exceptions`, and HTTP 4xx when
 * `monolog_handler.ignore_http_client_errors` is true) are not sent either. Symfony's ErrorListener
 * logs every uncaught 4xx at ERROR, so without this a 404 would become a Beacon issue even though
 * the exception listener skips it. The record still bubbles to the other handlers; records without
 * an exception are unaffected.
 */
final class BeaconMonologHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        /** @var list<string> */
        private readonly array $ignoreExceptions = [],
        private readonly bool $ignoreHttpClientErrors = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Forward a Monolog record to Beacon (exception context preferred).
     */
    protected function write(LogRecord $record): void
    {
        if (($record->context[EnvelopeTransport::LOG_CONTEXT_FLAG] ?? false) === true || !$this->client->isEnabled()) {
            return;
        }

        $extra = [
            'monolog' => [
                'channel' => $record->channel,
                'level'   => $record->level->getName(),
            ],
        ];

        $context   = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        if ($context !== []) {
            $extra['context'] = SensitiveValueRedactor::redactMap($context);
        }

        if ($exception instanceof Throwable) {
            if (IgnoredException::matches($exception, $this->ignoreExceptions, $this->ignoreHttpClientErrors)) {
                return;
            }

            $this->client->captureException($exception, $extra);

            return;
        }

        $this->client->captureMessage($record->message, $this->mapLevel($record), $extra);
    }

    /**
     * Map Monolog levels to Beacon string levels (`info` / `warning` / `error`).
     */
    private function mapLevel(LogRecord $record): string
    {
        return match ($record->level) {
            Level::Debug, Level::Info, Level::Notice => 'info',
            Level::Warning                           => 'warning',
            default                                  => 'error',
        };
    }
}
