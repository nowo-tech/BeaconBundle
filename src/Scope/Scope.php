<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scope;

use Symfony\Contracts\Service\ResetInterface;

use function count;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_substr;

/**
 * Request-scoped Hub context (tags) merged into outbound events and transactions.
 */
final class Scope implements ResetInterface
{
    public const MAX_TAGS = 32;

    public const MAX_KEY_LENGTH = 32;

    public const MAX_VALUE_LENGTH = 200;

    /** @var array<string, string> */
    private array $tags = [];

    /**
     * Set a single tag (merged into the scope). Invalid keys/values are ignored.
     */
    public function setTag(string $key, mixed $value): void
    {
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === null) {
            return;
        }

        $normalizedValue = $this->normalizeValue($value);
        if ($normalizedValue === null) {
            return;
        }

        if (!isset($this->tags[$normalizedKey]) && count($this->tags) >= self::MAX_TAGS) {
            return;
        }

        $this->tags[$normalizedKey] = $normalizedValue;
    }

    /**
     * Merge tags into the scope (existing keys are overwritten).
     *
     * @param array<string, mixed> $tags
     */
    public function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            $this->setTag((string) $key, $value);
        }
    }

    /**
     * Remove one tag by key.
     */
    public function removeTag(string $key): void
    {
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === null) {
            return;
        }

        unset($this->tags[$normalizedKey]);
    }

    /**
     * Clear all tags.
     */
    public function clearTags(): void
    {
        $this->tags = [];
    }

    /**
     * Current tags (key → string value).
     *
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->clearTags();
    }

    private function normalizeKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        return mb_substr($key, 0, self::MAX_KEY_LENGTH);
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return mb_substr((string) $value, 0, self::MAX_VALUE_LENGTH);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return null;
    }
}
