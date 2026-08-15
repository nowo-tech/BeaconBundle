<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Support\SensitiveValueRedactor;
use Throwable;

/**
 * Forwards Monolog records to Beacon as messages (optional; requires monolog/monolog).
 */
final class BeaconMonologHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly BeaconClientInterface $client,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Forward a Monolog record to Beacon (exception context preferred).
     */
    protected function write(LogRecord $record): void
    {
        if (!$this->client->isEnabled()) {
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
