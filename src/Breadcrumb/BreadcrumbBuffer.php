<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Breadcrumb;

use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer as CoreBreadcrumbBuffer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped breadcrumb trail attached to the next captured event/transaction (at most 50 items).
 *
 * Extends beacon-php's {@see CoreBreadcrumbBuffer} and is reset by Symfony between requests / worker jobs.
 */
final class BreadcrumbBuffer extends CoreBreadcrumbBuffer implements ResetInterface
{
}
