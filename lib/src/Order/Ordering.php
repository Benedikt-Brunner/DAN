<?php

declare(strict_types=1);

namespace Dan\Lib\Order;

/**
 * The outcome of comparing two items, backed by the comparator integer PHP's
 * sort functions and the spaceship operator use.
 */
enum Ordering: int
{
    case Less = -1;
    case Equal = 0;
    case Greater = 1;

    public static function between(int|float $left, int|float $right): self
    {
        return self::from($left <=> $right);
    }

    public function reverse(): self
    {
        return self::from(-$this->value);
    }
}
