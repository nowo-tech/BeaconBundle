<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Throwable;

/**
 * Public API for reporting events to a Beacon instance.
 */
interface BeaconClientInterface
{
    public function isEnabled(): bool;

    /**
     * Capture an exception and send it as an Envelope event item.
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     *
     * @return string|null Event id when accepted by the local builder (null when disabled)
     */
    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string;

    /**
     * Capture a free-form message.
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     */
    public function captureMessage(
        string $message,
        string $level = 'error',
        array $extra = [],
        ?array $fingerprint = null,
    ): ?string;
}
