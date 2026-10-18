<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Scheduler;

/**
 * Service whose methods are scheduled with #[AsPeriodicTask] / #[AsCronTask] (tests only).
 */
final class DocumentedTasks
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * Purges expired password-reset tokens! Runs in batches.
     *
     * @param int $limit never sent
     */
    public function purgeTokens(int $limit = 100): void
    {
        $this->calls[] = 'purge:' . $limit;
    }

    /** Warms the {@link https://example.com/cache HTTP cache} for the home page */
    public function warmCache(): void
    {
        $this->calls[] = 'warm';
    }

    public function undocumented(): void
    {
        $this->calls[] = 'undocumented';
    }
}
