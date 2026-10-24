<?php

declare(strict_types=1);

namespace Dan\Lib\Order;

/**
 * An item with a partial order: some pairs of items may be incomparable,
 * which comparison-based sorting cannot handle - sort them through
 * `Sort::topologically()`. Implementations compare only against their own
 * kind and refuse anything else.
 */
interface Ordered
{
    /**
     * @return Ordering|null null when the two items are incomparable
     */
    public function compareTo(self $other): ?Ordering;
}
