<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Throwable;

/**
 * No-op client used when Beacon is disabled or DSN is empty.
 */
final class NullBeaconClient implements BeaconClientInterface
{
    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function captureMessage(
        string $message,
        string $level = 'error',
        array $extra = [],
        ?array $fingerprint = null,
    ): ?string {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function addBreadcrumb(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = [],
    ): void {
    }

    /**
     * {@inheritdoc}
     */
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
