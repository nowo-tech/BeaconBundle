<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
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

    protected function write(LogRecord $record): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $extra = [
            'monolog' => true,
            'channel' => $record->channel,
        ];
        if ($record->context !== []) {
            $extra['context'] = $record->context;
        }

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $this->client->captureException($exception, $extra);

            return;
        }

        $this->client->captureMessage($record->message, $this->mapLevel($record), $extra);
    }

    private function mapLevel(LogRecord $record): string
    {
        return match ($record->level) {
            Level::Debug, Level::Info, Level::Notice => 'info',
            Level::Warning => 'warning',
            default => 'error',
        };
    }
}
