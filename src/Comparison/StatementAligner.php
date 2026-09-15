<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

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
        $matches = self::longestCommonSubsequence(baseline: $baseline, candidate: $candidate);

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
            $baselineEnd = $match === null ? count($baseline) : $match[0];
            $candidateEnd = $match === null ? count($candidate) : $match[1];
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
                $items[] = new AlignedStatement(kind: AlignmentKind::Unchanged, baselineIndex: $match[0], candidateIndex: $match[1]);
                $baselineCursor = $match[0] + 1;
                $candidateCursor = $match[1] + 1;
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
     *
     * @return list<array{int, int}> matched (baseline, candidate) index pairs in order
     */
    private static function longestCommonSubsequence(array $baseline, array $candidate): array
    {
        $baselineCount = count($baseline);
        $candidateCount = count($candidate);
        // lengths[i][j] = LCS length of baseline[i..] and candidate[j..].
        $lengths = array_fill(0, $baselineCount + 1, array_fill(0, $candidateCount + 1, 0));
        for ($i = $baselineCount - 1; $i >= 0; --$i) {
            for ($j = $candidateCount - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $baseline[$i] === $candidate[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $matches = [];
        $i = 0;
        $j = 0;
        while ($i < $baselineCount && $j < $candidateCount) {
            if ($baseline[$i] === $candidate[$j]) {
                $matches[] = [
                    $i,
                    $j,
                ];
                ++$i;
                ++$j;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                ++$i;
            } else {
                ++$j;
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $baseline
     * @param list<string> $candidate
     * @param list<int> $baselineGap unmatched baseline positions between two anchors
     * @param list<int> $candidateGap unmatched candidate positions between the same anchors
     * @param array{int, int}|null $previousMatch
     * @param array{int, int}|null $nextMatch
     *
     * @return list<AlignedStatement>
     */
    private static function classifyGap(
        array $baseline,
        array $candidate,
        array $baselineGap,
        array $candidateGap,
        ?array $previousMatch,
        ?array $nextMatch,
    ): array {
        $baselineAnchors = array_values(array_filter([
            $previousMatch === null ? null : $baseline[$previousMatch[0]],
            $nextMatch === null ? null : $baseline[$nextMatch[0]],
        ], is_string(...)));
        $candidateAnchors = array_values(array_filter([
            $previousMatch === null ? null : $candidate[$previousMatch[1]],
            $nextMatch === null ? null : $candidate[$nextMatch[1]],
        ], is_string(...)));

        $items = [];
        $pairs = min(count($baselineGap), count($candidateGap));
        for ($k = 0; $k < $pairs; ++$k) {
            $ambiguous = in_array($baseline[$baselineGap[$k]], $baselineAnchors, true)
                || in_array($candidate[$candidateGap[$k]], $candidateAnchors, true);
            $items[] = new AlignedStatement(
                kind: $ambiguous ? AlignmentKind::Ambiguous : AlignmentKind::Modified,
                baselineIndex: $baselineGap[$k],
                candidateIndex: $candidateGap[$k],
            );
        }
        foreach (array_slice($baselineGap, $pairs) as $index) {
            $items[] = new AlignedStatement(
                kind: in_array($baseline[$index], $baselineAnchors, true) ? AlignmentKind::Ambiguous : AlignmentKind::Removed,
                baselineIndex: $index,
                candidateIndex: null,
            );
        }
        foreach (array_slice($candidateGap, $pairs) as $index) {
            $items[] = new AlignedStatement(
                kind: in_array($candidate[$index], $candidateAnchors, true) ? AlignmentKind::Ambiguous : AlignmentKind::Inserted,
                baselineIndex: null,
                candidateIndex: $index,
            );
        }

        return $items;
    }
}
