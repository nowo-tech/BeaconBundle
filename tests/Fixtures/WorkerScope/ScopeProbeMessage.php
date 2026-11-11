<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\WorkerScope;

/**
 * Message of the worker-scope test: the handler records `$user` in the scope and fails when `$fail` is true.
 */
final readonly class ScopeProbeMessage
{
    public function __construct(
        public string $user,
        public int $iteration,
        public bool $fail,
    ) {
    }
}
