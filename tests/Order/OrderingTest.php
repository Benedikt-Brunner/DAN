<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Order;

use Dan\Lib\Order\Ordering;
use PHPUnit\Framework\TestCase;

final class OrderingTest extends TestCase
{
    public function testOrdersNumbersLikeTheSpaceshipOperator(): void
    {
        self::assertSame(Ordering::Less, Ordering::between(left: 1, right: 2));
        self::assertSame(Ordering::Equal, Ordering::between(left: 2, right: 2.0));
        self::assertSame(Ordering::Greater, Ordering::between(left: 0.5, right: -0.5));
    }

    public function testReverseSwapsLessAndGreaterAndKeepsEqual(): void
    {
        self::assertSame(Ordering::Greater, Ordering::Less->reverse());
        self::assertSame(Ordering::Equal, Ordering::Equal->reverse());
        self::assertSame(Ordering::Less, Ordering::Greater->reverse());
    }
}
