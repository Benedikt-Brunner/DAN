<?php

declare(strict_types=1);

namespace Dan\Lib\Order;

/**
 * An item with a total order: every pair of items is comparable.
 */
interface TotallyOrdered extends Ordered
{
    public function compareTo(Ordered $other): Ordering;
}
