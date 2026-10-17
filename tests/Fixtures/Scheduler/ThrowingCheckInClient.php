<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\CheckInCapableInterface;
use RuntimeException;
use Throwable;

/**
 * Enabled client whose check-in sends always fail (Beacon outage).
 */
final class ThrowingCheckInClient implements BeaconClientInterface, CheckInCapableInterface
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        return null;
    }

    public function captureMessage(string $message, string $level = 'error', array $extra = [], ?array $fingerprint = null): ?string
    {
        return null;
    }

    public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
    }

    public function captureTransaction(string $transactionName, float $startTimestamp, float $endTimestamp, array $spans = [], array $extra = []): ?string
    {
        return null;
    }

    public function setTag(string $key, mixed $value): void
    {
    }

    public function setTags(array $tags): void
    {
    }

    public function getTags(): array
    {
        return [];
    }

    public function clearTags(): void
    {
    }

    public function captureScheduleCatalog(array $schedules): ?string
    {
        throw new RuntimeException('Beacon unreachable');
    }

    public function captureCheckIn(array $checkIn): ?string
    {
        throw new RuntimeException('Beacon unreachable');
    }
}
