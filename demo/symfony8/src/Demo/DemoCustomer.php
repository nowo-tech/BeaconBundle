<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Object with __toString(), like many entities that expose a readable label.
 */
final class DemoCustomer implements \Stringable
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
    ) {
    }

    /**
     * Human-readable label for dump / Twig samples.
     */
    public function __toString(): string
    {
        return \sprintf('Customer #%d (%s)', $this->id, $this->name);
    }
}
