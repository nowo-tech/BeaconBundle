<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Breadcrumb;

use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer as CoreBreadcrumbBuffer;
use Symfony\Contracts\Service\ResetInterface;

use function trigger_deprecation;

/**
 * Deprecated thin wrapper around beacon-php's {@see CoreBreadcrumbBuffer}, kept so code that type-hints this class
 * keeps working.
 *
 * Since 1.27.0 the bundle registers {@see CoreBreadcrumbBuffer} itself as the request-scoped service (tagged
 * `kernel.reset`). This class holds no state of its own: the service `Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer`
 * wraps that same core instance. `new BreadcrumbBuffer()` wraps a fresh core instance.
 *
 * @deprecated since nowo-tech/beacon-bundle 1.27.0, type-hint {@see CoreBreadcrumbBuffer} (service id
 *             `Nowo\Beacon\Breadcrumb\BreadcrumbBuffer`) instead; this class is removed in 2.0
 */
final readonly class BreadcrumbBuffer implements ResetInterface
{
    public const MAX_ITEMS = CoreBreadcrumbBuffer::MAX_ITEMS;

    private CoreBreadcrumbBuffer $inner;

    public function __construct(?CoreBreadcrumbBuffer $inner = null)
    {
        trigger_deprecation('nowo-tech/beacon-bundle', '1.27.0', 'The "%s" class is deprecated, type-hint "%s" instead.', self::class, CoreBreadcrumbBuffer::class);

        $this->inner = $inner ?? new CoreBreadcrumbBuffer();
    }

    /**
     * The beacon-php buffer this wrapper delegates to.
     */
    public function getInner(): CoreBreadcrumbBuffer
    {
        return $this->inner;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function add(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = [],
        string $type = 'default',
    ): void {
        $this->inner->add($message, $category, $level, $data, $type);
    }

    /**
     * @return list<array{timestamp: float, type: string, category: string, level: string, message: string, data: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->inner->all();
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
