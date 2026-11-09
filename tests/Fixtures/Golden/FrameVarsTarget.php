<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Golden;

use Nowo\BeaconBundle\Support\FrameVarsSerializer;

use function count;

/**
 * Real method whose parameter names {@see FrameVarsSerializer} reads for the golden
 * frame `vars`.
 */
final class FrameVarsTarget
{
    /**
     * @param array<string, string> $options
     */
    public function login(string $sessionId, string $userPin, array $options): string
    {
        return $sessionId . $userPin . count($options);
    }
}
