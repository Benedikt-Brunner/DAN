<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Sequence;

use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Dan\Lib\Sequence\LongestCommonSubsequence;
use Eris\Generator;

/**
 * Over random short sequences with repeats, the result is a common
 * subsequence (strictly increasing on both sides, equal items) and no
 * common subsequence is longer - checked against an exhaustive reference.
 */
final class LongestCommonSubsequencePropertyTest extends PropertyTestCase
{
    public function testTheResultIsALongestCommonSubsequence(): void
    {
        $this->forAll($this->sequences(), $this->sequences())->then(function (mixed $left, mixed $right): void {
            $left = DomainGenerators::asStringList($left);
            $right = DomainGenerators::asStringList($right);

            $matches = LongestCommonSubsequence::of(left: $left, right: $right);

            $previousLeftIndex = -1;
            $previousRightIndex = -1;
            foreach ($matches as $match) {
                self::assertGreaterThan($previousLeftIndex, $match->leftIndex);
                self::assertGreaterThan($previousRightIndex, $match->rightIndex);
                self::assertSame($left[$match->leftIndex], $right[$match->rightIndex]);
                $previousLeftIndex = $match->leftIndex;
                $previousRightIndex = $match->rightIndex;
            }
            self::assertCount(self::referenceLength(left: $left, right: $right), $matches);
        });
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private static function referenceLength(array $left, array $right): int
    {
        if ($left === [] || $right === []) {
            return 0;
        }
        $leftRest = array_slice($left, 1);
        $rightRest = array_slice($right, 1);
        if ($left[0] === $right[0]) {
            return 1 + self::referenceLength(left: $leftRest, right: $rightRest);
        }

        return max(self::referenceLength(left: $leftRest, right: $right), self::referenceLength(left: $left, right: $rightRest));
    }

    /**
     * Sequences of up to six items drawn from three values, so repeats - and
     * with them several equally long common subsequences - are common.
     *
     * @return Generator<mixed>
     */
    private function sequences(): Generator
    {
        return DomainGenerators::boundedList(elements: Generator\elements('A', 'B', 'C'), maxLength: 6);
    }
}
