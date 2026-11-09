<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scope;

use Nowo\Beacon\Scope\Scope as CoreScope;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped Hub context (tags) merged into outbound events and transactions.
 *
 * Extends beacon-php's {@see CoreScope} (same tag rules: {@see MAX_TAGS} tags, keys ≤ {@see MAX_KEY_LENGTH}
 * characters, values ≤ {@see MAX_VALUE_LENGTH}) and is reset by Symfony between requests / worker jobs.
 */
final class Scope extends CoreScope implements ResetInterface
{
}
