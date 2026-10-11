<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Order;

use Dan\Harness\Tests\Order\Fixtures\Divisor;
use Dan\Harness\Tests\Order\Fixtures\Rank;
use Dan\Lib\Order\Sort;
use PHPUnit\Framework\TestCase;

final class SortTest extends TestCase
{
    public function testAscendingSortsByTheTotalOrderAndKeepsEqualItemsInInputOrder(): void
    {
        $sorted = Sort::ascending([
            new Rank(rank: 3, label: 'c'),
            new Rank(rank: 1, label: 'first one'),
            new Rank(rank: 2, label: 'b'),
            new Rank(rank: 1, label: 'second one'),
        ]);

        self::assertSame([
            'first one',
            'second one',
            'b',
            'c',
        ], self::labels($sorted));
    }

    public function testDescendingSortsByTheReversedTotalOrderAndKeepsEqualItemsInInputOrder(): void
    {
        $sorted = Sort::descending([
            new Rank(rank: 1, label: 'a'),
            new Rank(rank: 3, label: 'first three'),
            new Rank(rank: 2, label: 'b'),
            new Rank(rank: 3, label: 'second three'),
        ]);

        self::assertSame([
            'first three',
            'second three',
            'b',
            'a',
        ], self::labels($sorted));
    }

    public function testTopologicallyPlacesEveryItemAfterAllItemsLessThanIt(): void
    {
        $sorted = Sort::topologically([
            new Divisor(12),
            new Divisor(3),
            new Divisor(4),
            new Divisor(2),
            new Divisor(6),
        ]);

        self::assertSame([
            3,
            2,
            4,
            6,
            12,
        ], self::values($sorted));
    }

    public function testTopologicallyKeepsIncomparableItemsInInputOrder(): void
    {
        $sorted = Sort::topologically([
            new Divisor(7),
            new Divisor(3),
            new Divisor(5),
        ]);

        self::assertSame([
            7,
            3,
            5,
        ], self::values($sorted));
    }

    /**
     * @param list<Rank> $ranks
     *
     * @return list<string>
     */
    private static function labels(array $ranks): array
    {
        return array_map(fn (Rank $rank): string => $rank->label, $ranks);
    }

    /**
     * @param list<Divisor> $divisors
     *
     * @return list<int>
     */
    private static function values(array $divisors): array
    {
        return array_map(fn (Divisor $divisor): int => $divisor->value, $divisors);
    }
}
