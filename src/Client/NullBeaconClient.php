<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Throwable;

/**
 * No-op client used when Beacon is disabled or DSN is empty.
 */
final class NullBeaconClient implements BeaconClientInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        return null;
    }

    public function captureMessage(
        string $message,
        string $level = 'error',
        array $extra = [],
        ?array $fingerprint = null,
    ): ?string {
        return null;
    }

    public function addBreadcrumb(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = [],
    ): void {
    }

    public function captureTransaction(
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): ?string {
        return null;
    }
}
