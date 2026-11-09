<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Trace;

use Nowo\Beacon\Trace\TraceIdProvider as CoreTraceIdProvider;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request/worker-scoped correlation id attached to every Beacon event.
 *
 * Extends beacon-php's {@see CoreTraceIdProvider} (same {@see HEADER}, same id rules) and is reset by Symfony
 * between requests / worker jobs.
 */
final class TraceIdProvider extends CoreTraceIdProvider implements ResetInterface
{
    public const REQUEST_ATTRIBUTE = '_beacon_trace_id';
}
