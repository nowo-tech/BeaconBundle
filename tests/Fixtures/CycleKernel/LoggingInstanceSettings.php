<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\CycleKernel;

use Nowo\BeaconBundle\Tests\Fixtures\Credentials\FakeInstanceSettings;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Psr\Log\LoggerInterface;

/**
 * InstanceSettings that needs the logger to be built (as the real one does through Doctrine) and logs an error while it
 * reads, so that the log record goes through the Beacon Monolog handler in the middle of a credentials read.
 */
final class LoggingInstanceSettings implements InstanceSettingsInterface
{
    public int $reads = 0;

    private readonly FakeInstanceSettings $inner;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->inner = new FakeInstanceSettings();
    }

    public function getString(string $key): ?string
    {
        return $this->inner->getString($key);
    }

    public function getSecret(string $key): ?string
    {
        if ($key === 'beacon.dsn') {
            ++$this->reads;
            $this->logger->error('Slow settings query while reading {key}.', ['key' => $key]);
        }

        return $this->inner->getSecret($key);
    }

    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array
    {
        return $this->inner->save($section, $values, $origin);
    }
}
