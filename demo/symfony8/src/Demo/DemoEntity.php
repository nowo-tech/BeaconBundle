<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Plain object simulating a Doctrine entity without __toString().
 */
final class DemoEntity
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
