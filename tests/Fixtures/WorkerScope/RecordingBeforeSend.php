<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\WorkerScope;

/**
 * `before_send` hook that records every event / transaction payload and lets it through (tests only).
 */
final class RecordingBeforeSend
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $payload): array
    {
        $this->payloads[] = $payload;

        return $payload;
    }
}
