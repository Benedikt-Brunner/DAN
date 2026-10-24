<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Order\Fixtures;

use Dan\Lib\Order\Ordered;
use Dan\Lib\Order\Ordering;
use Dan\Lib\Order\TotallyOrdered;
use LogicException;

/**
 * Totally ordered by rank; the label tells equal ranks apart so stability
 * is observable.
 */
final class Rank implements TotallyOrdered
{
    public function __construct(
        public readonly int $rank,
        public readonly string $label,
    ) {}

    public function compareTo(Ordered $other): Ordering
    {
        if (!$other instanceof self) {
            throw new LogicException('Ranks only compare against ranks.');
        }

        return Ordering::between(left: $this->rank, right: $other->rank);
    }
}
