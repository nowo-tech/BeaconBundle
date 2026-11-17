<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scope;

use Nowo\Beacon\Scope\Scope as CoreScope;
use Symfony\Contracts\Service\ResetInterface;

use function trigger_deprecation;

/**
 * Deprecated thin wrapper around beacon-php's {@see CoreScope}, kept so code that type-hints this class keeps working.
 *
 * Since 1.27.0 the bundle registers {@see CoreScope} itself as the request-scoped service (tagged `kernel.reset`). This
 * class holds no state of its own: the service `Nowo\BeaconBundle\Scope\Scope` wraps that same core instance, so tags
 * set through either object reach the same events. `new Scope()` wraps a fresh core instance.
 *
 * @deprecated since nowo-tech/beacon-bundle 1.27.0, type-hint {@see CoreScope} (service id `Nowo\Beacon\Scope\Scope`)
 *             instead; this class is removed in 2.0
 */
final readonly class Scope implements ResetInterface
{
    public const MAX_TAGS = CoreScope::MAX_TAGS;

    public const MAX_KEY_LENGTH = CoreScope::MAX_KEY_LENGTH;

    public const MAX_VALUE_LENGTH = CoreScope::MAX_VALUE_LENGTH;

    public const MAX_EXTRA = CoreScope::MAX_EXTRA;

    private CoreScope $inner;

    public function __construct(?CoreScope $inner = null)
    {
        trigger_deprecation('nowo-tech/beacon-bundle', '1.27.0', 'The "%s" class is deprecated, type-hint "%s" instead.', self::class, CoreScope::class);

        $this->inner = $inner ?? new CoreScope();
    }

    /**
     * The beacon-php scope this wrapper delegates to.
     */
    public function getInner(): CoreScope
    {
        return $this->inner;
    }

    public function setTag(string $key, mixed $value): void
    {
        $this->inner->setTag($key, $value);
    }

    /**
     * @param array<array-key, mixed> $tags
     */
    public function setTags(array $tags): void
    {
        $this->inner->setTags($tags);
    }

    public function removeTag(string $key): void
    {
        $this->inner->removeTag($key);
    }

    public function clearTags(): void
    {
        $this->inner->clearTags();
    }

    /**
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->inner->getTags();
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->inner->setUser($user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->inner->getUser();
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->inner->setExtra($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->inner->getExtra();
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
