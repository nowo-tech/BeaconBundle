<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

/**
 * Outcome of a synchronous Envelope POST (used by connection tests and diagnostics).
 */
final class TransportResult
{
    public function __construct(
        private readonly bool $accepted,
        private readonly ?int $statusCode = null,
        private readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Whether Beacon accepted the envelope (HTTP 2xx) or the request completed successfully.
     */
    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    /**
     * HTTP status when a response was received.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Short error description for transport failures (no secrets).
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
