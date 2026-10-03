<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Order\Fixtures;

use Dan\Lib\Order\Ordered;
use Dan\Lib\Order\Ordering;
use LogicException;

/**
 * Partially ordered by divisibility: 2 < 6, but 2 and 3 are incomparable.
 */
final class Divisor implements Ordered
{
    public function __construct(
        public readonly int $value,
    ) {}

    public function compareTo(Ordered $other): ?Ordering
    {
        if (!$other instanceof self) {
            throw new LogicException('Divisors only compare against divisors.');
        }

        return match (true) {
            $this->value === $other->value => Ordering::Equal,
            $other->value % $this->value === 0 => Ordering::Less,
            $this->value % $other->value === 0 => Ordering::Greater,
            default => null,
        };
    }
}
