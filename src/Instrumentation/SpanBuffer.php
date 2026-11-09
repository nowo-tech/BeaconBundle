<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use Nowo\Beacon\Span\SpanBuffer as CoreSpanBuffer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped performance spans attached to the next transaction (and dual-written as breadcrumbs).
 *
 * Extends beacon-php's {@see CoreSpanBuffer} (at most 100 spans) and is reset by Symfony between requests / worker
 * jobs.
 */
final class SpanBuffer extends CoreSpanBuffer implements ResetInterface
{
}
