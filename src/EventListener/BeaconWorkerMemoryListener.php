<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\EventListener;

use Closure;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

use function implode;
use function ini_get;
use function is_string;
use function max;
use function memory_get_usage;
use function min;
use function round;
use function sort;
use function sprintf;
use function strtolower;
use function substr;
use function trim;

/**
 * Warns once per Messenger worker process when memory usage crosses a ratio of PHP `memory_limit`
 * (opt-in via `messenger.worker_memory_warning_ratio`).
 *
 * Catches leaks before the worker dies with a fatal "Allowed memory size exhausted" (which the fatal
 * handler reports with no worker context). Connection / transport errors that stop `messenger:consume`
 * are already reported by the console error listener.
 */
final class BeaconWorkerMemoryListener implements EventSubscriberInterface
{
    private bool $warned = false;

    /** @var list<string> */
    private array $transports = [];

    private readonly float $ratio;

    /** @var Closure(): int */
    private readonly Closure $memoryUsage;

    /** @var Closure(): int */
    private readonly Closure $memoryLimit;

    /**
     * @param (Closure(): int)|null $memoryUsage Bytes in use; injectable for tests
     * @param (Closure(): int)|null $memoryLimit Bytes allowed (`<= 0` = unlimited); injectable for tests
     */
    public function __construct(
        private readonly BeaconClientInterface $client,
        float $ratio = 0.8,
        ?Closure $memoryUsage = null,
        ?Closure $memoryLimit = null,
    ) {
        $this->ratio       = max(0.05, min(1.0, $ratio));
        $this->memoryUsage = $memoryUsage ?? static fn (): int => memory_get_usage(true);
        $this->memoryLimit = $memoryLimit ?? static fn (): int => self::parseBytes(ini_get('memory_limit'));
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        // @igor-ignore - Per Messenger worker process (re-armed on WorkerStartedEvent); a per-message reset would repeat the warning.
        $this->warned = false;
        $transports   = $event->getWorker()->getMetadata()->getTransportNames();
        sort($transports);
        // @igor-ignore - Per Messenger worker process (re-armed on WorkerStartedEvent); a per-message reset would repeat the warning.
        $this->transports = $transports;
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->warned || !$this->client->isEnabled()) {
            return;
        }

        $limit = ($this->memoryLimit)();
        if ($limit <= 0) {
            return;
        }

        $usage = ($this->memoryUsage)();
        if ($usage < $limit * $this->ratio) {
            return;
        }

        // @igor-ignore - Per Messenger worker process (re-armed on WorkerStartedEvent); a per-message reset would repeat the warning.
        $this->warned = true;
        $transports   = implode(',', $this->transports);

        $this->client->captureMessage(
            sprintf(
                'Messenger worker memory at %d%% of memory_limit (%s)',
                (int) round($usage / $limit * 100),
                $transports !== '' ? $transports : 'unknown transports',
            ),
            'warning',
            [
                'messenger_worker' => [
                    'transports'         => $this->transports,
                    'memory_usage_bytes' => $usage,
                    'memory_limit_bytes' => $limit,
                    'warning_ratio'      => $this->ratio,
                    'idle'               => $event->isWorkerIdle(),
                ],
            ],
            ['beacon.messenger.worker_memory', $transports],
        );
    }

    /**
     * Parse a php.ini byte value (`512M`, `1G`, `-1`); `<= 0` means unlimited.
     */
    public static function parseBytes(string|false $value): int
    {
        if (!is_string($value)) {
            return -1;
        }

        $value = strtolower(trim($value));
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (substr($value, -1)) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
