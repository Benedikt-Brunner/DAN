<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Lib\Sequence\LongestCommonSubsequence;
use Dan\Lib\Sequence\MatchedPosition;

/**
 * Aligns two statement sequences by their normalized SQL fingerprints with
 * an order-preserving sequence diff (longest common subsequence). Removing or
 * inserting one statement therefore reports one removal or insertion, and
 * every later identical statement stays unchanged instead of showing up as
 * "changed" because its position shifted.
 *
 * Between two matched anchors, unmatched baseline and candidate statements
 * are paired up in order as modifications; whatever is left over on either
 * side is a removal or insertion. An unmatched statement whose fingerprint
 * equals one of the anchors next to its gap could just as well have been the
 * matched one - such items are reported ambiguous rather than forced into a
 * classification the data does not support.
 */
final class StatementAligner
{
    /**
     * @param list<string> $baseline normalized SQL fingerprints in sequence order
     * @param list<string> $candidate normalized SQL fingerprints in sequence order
     */
    public static function align(array $baseline, array $candidate): StatementAlignment
    {
        // Left is the baseline, right the candidate.
        $matches = LongestCommonSubsequence::of(left: $baseline, right: $candidate);

        $items = [];
        $baselineCursor = 0;
        $candidateCursor = 0;
        $previousMatch = null;
        // A trailing null stands for "the end of both sequences" so the gap
        // after the last match is classified like every other gap.
        $anchors = [
            ...$matches,
            null,
        ];
        foreach ($anchors as $match) {
            $baselineEnd = $match === null ? count($baseline) : $match->leftIndex;
            $candidateEnd = $match === null ? count($candidate) : $match->rightIndex;
            $items = [
                ...$items,
                ...self::classifyGap(
                    baseline: $baseline,
                    candidate: $candidate,
                    baselineGap: self::positions(from: $baselineCursor, toExclusive: $baselineEnd),
                    candidateGap: self::positions(from: $candidateCursor, toExclusive: $candidateEnd),
                    previousMatch: $previousMatch,
                    nextMatch: $match,
                ),
            ];
            if ($match !== null) {
                $items[] = new AlignedStatement(kind: AlignmentKind::Unchanged, baselineIndex: $match->leftIndex, candidateIndex: $match->rightIndex);
                $baselineCursor = $match->leftIndex + 1;
                $candidateCursor = $match->rightIndex + 1;
                $previousMatch = $match;
            }
        }

        return new StatementAlignment($items);
    }

    /**
     * @return list<int> the positions from..toExclusive-1, empty when the range is empty
     */
    private static function positions(int $from, int $toExclusive): array
    {
        return $from < $toExclusive ? range($from, $toExclusive - 1) : [];
    }

    /**
     * @param list<string> $baseline
     * @param list<string> $candidate
     * @param list<int> $baselineGap unmatched baseline positions between two anchors
     * @param list<int> $candidateGap unmatched candidate positions between the same anchors
     * @param MatchedPosition|null $previousMatch the anchor before the gap, null at the start of both sequences
     * @param MatchedPosition|null $nextMatch the anchor after the gap, null at the end of both sequences
     *
     * @return list<AlignedStatement>
     */
    private static function classifyGap(
        array $baseline,
        array $candidate,
        array $baselineGap,
        array $candidateGap,
        ?MatchedPosition $previousMatch,
        ?MatchedPosition $nextMatch,
    ): array {
        $baselineAnchors = array_values(array_filter([
            $previousMatch === null ? null : $baseline[$previousMatch->leftIndex],
            $nextMatch === null ? null : $baseline[$nextMatch->leftIndex],
        ], is_string(...)));
        $candidateAnchors = array_values(array_filter([
            $previousMatch === null ? null : $candidate[$previousMatch->rightIndex],
            $nextMatch === null ? null : $candidate[$nextMatch->rightIndex],
        ], is_string(...)));

        $items = [];
        $pairCount = min(count($baselineGap), count($candidateGap));
        for ($gapOffset = 0; $gapOffset < $pairCount; ++$gapOffset) {
            $baselineIndex = $baselineGap[$gapOffset];
            $candidateIndex = $candidateGap[$gapOffset];
            $ambiguous = in_array($baseline[$baselineIndex], $baselineAnchors, true)
                || in_array($candidate[$candidateIndex], $candidateAnchors, true);
            $items[] = new AlignedStatement(
                kind: $ambiguous ? AlignmentKind::Ambiguous : AlignmentKind::Modified,
                baselineIndex: $baselineIndex,
                candidateIndex: $candidateIndex,
            );
        }
        foreach (array_slice($baselineGap, $pairCount) as $baselineIndex) {
            $items[] = new AlignedStatement(
                kind: in_array($baseline[$baselineIndex], $baselineAnchors, true) ? AlignmentKind::Ambiguous : AlignmentKind::Removed,
                baselineIndex: $baselineIndex,
                candidateIndex: null,
            );
        }
        foreach (array_slice($candidateGap, $pairCount) as $candidateIndex) {
            $items[] = new AlignedStatement(
                kind: in_array($candidate[$candidateIndex], $candidateAnchors, true) ? AlignmentKind::Ambiguous : AlignmentKind::Inserted,
                baselineIndex: null,
                candidateIndex: $candidateIndex,
            );
        }

        return $items;
    }
}
