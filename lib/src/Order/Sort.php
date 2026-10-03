<?php

declare(strict_types=1);

namespace Dan\Lib\Order;

use LogicException;

/**
 * The ways to sort ordered items. Every sort is stable: items that compare
 * equal, or are incomparable, keep their input order.
 */
final class Sort
{
    /**
     * @template Item of TotallyOrdered
     *
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    public static function ascending(array $items): array
    {
        usort($items, fn (TotallyOrdered $left, TotallyOrdered $right): int => $left->compareTo($right)->value);

        return $items;
    }

    /**
     * @template Item of TotallyOrdered
     *
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    public static function descending(array $items): array
    {
        usort($items, fn (TotallyOrdered $left, TotallyOrdered $right): int => $left->compareTo($right)->reverse()->value);

        return $items;
    }

    /**
     * A linear extension of a partial order: every item comes after all
     * items less than it. Comparison sorts are only correct for total orders,
     * so this repeatedly takes the first remaining item that nothing
     * remaining is less than - quadratic, the price of incomparable pairs.
     *
     * @template Item of Ordered
     *
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    public static function topologically(array $items): array
    {
        $sorted = [];
        $remaining = $items;
        while ($remaining !== []) {
            $minimal = self::firstMinimal($remaining);
            $sorted[] = $remaining[$minimal];
            unset($remaining[$minimal]);
        }

        return $sorted;
    }

    /**
     * @param non-empty-array<int, Ordered> $items
     */
    private static function firstMinimal(array $items): int
    {
        foreach ($items as $position => $item) {
            if (!self::hasPredecessor(item: $item, items: $items)) {
                return $position;
            }
        }

        throw new LogicException('Ordered items form a cycle: every remaining item has a predecessor.');
    }

    /**
     * @param array<int, Ordered> $items
     */
    private static function hasPredecessor(Ordered $item, array $items): bool
    {
        foreach ($items as $other) {
            if ($other->compareTo($item) === Ordering::Less) {
                return true;
            }
        }

        return false;
    }
}
