<?php

declare(strict_types=1);

namespace Dan\Lib\Sequence;

/**
 * The order-preserving longest common subsequence of two sequences, the core
 * of a sequence diff: items compare with strict equality, and the result
 * names the matched positions in both sequences, in sequence order.
 */
final class LongestCommonSubsequence
{
    /**
     * Among equally long common subsequences this prefers the one that keeps
     * the two positions side by side: when leaving both current items
     * unmatched still yields an optimal subsequence, they are a substitution
     * pair, not a removal here and an insertion somewhere further down.
     * Without this rule a repeated item after a change lets the match drift
     * onto the wrong copy, and a substituted position reads as matched.
     *
     * @template Item
     *
     * @param list<Item> $left
     * @param list<Item> $right
     *
     * @return list<MatchedPosition>
     */
    public static function of(array $left, array $right): array
    {
        $leftCount = count($left);
        $rightCount = count($right);
        // suffixLengths[l][r] = LCS length of left[l..] and right[r..].
        $suffixLengths = array_fill(0, $leftCount + 1, array_fill(0, $rightCount + 1, 0));
        for ($leftPosition = $leftCount - 1; $leftPosition >= 0; --$leftPosition) {
            for ($rightPosition = $rightCount - 1; $rightPosition >= 0; --$rightPosition) {
                $suffixLengths[$leftPosition][$rightPosition] = $left[$leftPosition] === $right[$rightPosition]
                    ? $suffixLengths[$leftPosition + 1][$rightPosition + 1] + 1
                    : max($suffixLengths[$leftPosition + 1][$rightPosition], $suffixLengths[$leftPosition][$rightPosition + 1]);
            }
        }

        // Backtrack from the front, so ties resolve towards the earliest
        // positions and the side-by-side preference above applies.
        $matches = [];
        $leftPosition = 0;
        $rightPosition = 0;
        while ($leftPosition < $leftCount && $rightPosition < $rightCount) {
            if ($left[$leftPosition] === $right[$rightPosition]) {
                $matches[] = new MatchedPosition(leftIndex: $leftPosition, rightIndex: $rightPosition);
                ++$leftPosition;
                ++$rightPosition;
            } elseif ($suffixLengths[$leftPosition + 1][$rightPosition + 1] === $suffixLengths[$leftPosition][$rightPosition]) {
                ++$leftPosition;
                ++$rightPosition;
            } elseif ($suffixLengths[$leftPosition + 1][$rightPosition] >= $suffixLengths[$leftPosition][$rightPosition + 1]) {
                ++$leftPosition;
            } else {
                ++$rightPosition;
            }
        }

        return $matches;
    }
}
