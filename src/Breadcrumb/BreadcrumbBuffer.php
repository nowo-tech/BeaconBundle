<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Breadcrumb;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped breadcrumb trail attached to the next captured event/transaction.
 */
final class BreadcrumbBuffer implements ResetInterface
{
    private const MAX_ITEMS = 50;

    /** @var list<array{timestamp: float, type: string, category: string, level: string, message: string, data: array<string, mixed>}> */
    private array $items = [];

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
        $this->items[] = [
            'timestamp' => microtime(true),
            'type'      => $type,
            'category'  => $category,
            'level'     => $level,
            'message'   => $message,
            'data'      => $data,
        ];

        if (count($this->items) > self::MAX_ITEMS) {
            $this->items = array_slice($this->items, -self::MAX_ITEMS);
        }
    }

    /**
     * @return list<array{timestamp: float, type: string, category: string, level: string, message: string, data: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function reset(): void
    {
        $this->clear();
    }
}
