<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Connection;

/**
 * Structured outcome of {@see BeaconConnectionTester::test()}.
 */
final class ConnectionTestResult
{
    /**
     * @param array{
     *     origin?: string,
     *     project_id?: string,
     *     public_key?: string,
     *     envelope_url?: string,
     *     reporting_enabled?: bool
     * } $target Sanitized connection metadata (never includes the secret)
     */
    public function __construct(
        private readonly bool $success,
        private readonly string $message,
        private readonly array $target = [],
        private readonly ?string $eventId = null,
        private readonly ?int $httpStatus = null,
        private readonly bool $sent = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array{
     *     origin?: string,
     *     project_id?: string,
     *     public_key?: string,
     *     envelope_url?: string,
     *     reporting_enabled?: bool
     * }
     */
    public function getTarget(): array
    {
        return $this->target;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Whether an Envelope POST was attempted (false for `--check-only` or pre-send failures).
     */
    public function wasSent(): bool
    {
        return $this->sent;
    }
}
