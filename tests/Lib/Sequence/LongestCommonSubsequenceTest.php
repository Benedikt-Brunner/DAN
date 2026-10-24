<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Sequence;

use Dan\Lib\Sequence\LongestCommonSubsequence;
use Dan\Lib\Sequence\MatchedPosition;
use PHPUnit\Framework\TestCase;

final class LongestCommonSubsequenceTest extends TestCase
{
    public function testIdenticalSequencesMatchEveryPosition(): void
    {
        $matches = LongestCommonSubsequence::of(left: [
            'A',
            'B',
            'C',
        ], right: [
            'A',
            'B',
            'C',
        ]);

        self::assertSame([
            '0->0',
            '1->1',
            '2->2',
        ], self::describe($matches));
    }

    public function testARemovedItemShiftsLaterMatchesByOne(): void
    {
        $matches = LongestCommonSubsequence::of(left: [
            'A',
            'B',
            'C',
            'D',
        ], right: [
            'A',
            'C',
            'D',
        ]);

        self::assertSame([
            '0->0',
            '2->1',
            '3->2',
        ], self::describe($matches));
    }

    public function testAnInsertedItemShiftsLaterMatchesByOne(): void
    {
        $matches = LongestCommonSubsequence::of(left: [
            'A',
            'C',
        ], right: [
            'A',
            'B',
            'C',
        ]);

        self::assertSame([
            '0->0',
            '1->2',
        ], self::describe($matches));
    }

    public function testSubstitutionsBeforeARepeatedItemStaySideBySide(): void
    {
        // Matching the second E on the left with the first E on the right
        // is just as long, but would turn the substitutions at 2 and 3 into
        // a removal plus an insertion further down.
        $matches = LongestCommonSubsequence::of(left: [
            'A',
            'B',
            'C',
            'D',
            'E',
            'E',
        ], right: [
            'A',
            'B',
            'X',
            'Y',
            'E',
            'Z',
        ]);

        self::assertSame([
            '0->0',
            '1->1',
            '4->4',
        ], self::describe($matches));
    }

    public function testDisjointSequencesShareNothing(): void
    {
        self::assertSame([], LongestCommonSubsequence::of(left: [
            'A',
            'B',
        ], right: [
            'C',
            'D',
        ]));
    }

    public function testEmptySequencesShareNothing(): void
    {
        self::assertSame([], LongestCommonSubsequence::of(left: [], right: ['A']));
        self::assertSame([], LongestCommonSubsequence::of(left: ['A'], right: []));
        self::assertSame([], LongestCommonSubsequence::of(left: [], right: []));
    }

    public function testItemsCompareStrictly(): void
    {
        self::assertSame([], LongestCommonSubsequence::of(left: [1], right: ['1']));
    }

    /**
     * @param list<MatchedPosition> $matches
     *
     * @return list<string>
     */
    private static function describe(array $matches): array
    {
        return array_map(
            fn (MatchedPosition $match): string => sprintf('%d->%d', $match->leftIndex, $match->rightIndex),
            $matches,
        );
    }
}
