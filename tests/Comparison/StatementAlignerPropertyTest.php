<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\AlignmentKind;
use Dan\Harness\Comparison\StatementAligner;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Eris\Generator;

/**
 * Invariants of the statement alignment over random fingerprint sequences
 * with repeats: identical sequences never register a change (the A/A
 * property at this layer), every position of both sequences is accounted
 * for exactly once, and removing one statement from a repeat-free sequence
 * is reported as exactly that.
 */
final class StatementAlignerPropertyTest extends PropertyTestCase
{
    public function testIdenticalSequencesAreNeverReportedAsChanged(): void
    {
        $this->forAll($this->fingerprints())->then(function (mixed $sequence): void {
            $sequence = DomainGenerators::asStringList($sequence);

            $alignment = StatementAligner::align(baseline: $sequence, candidate: $sequence);

            self::assertFalse($alignment->sqlChanged());
            self::assertSame(range(0, count($sequence) - 1), $alignment->baselineIndices(AlignmentKind::Unchanged));
            self::assertSame(range(0, count($sequence) - 1), $alignment->candidateIndices(AlignmentKind::Unchanged));
        });
    }

    public function testEveryPositionOfBothSequencesIsAlignedExactlyOnce(): void
    {
        $this->forAll($this->fingerprints(), $this->fingerprints())->then(function (mixed $baseline, mixed $candidate): void {
            $baseline = DomainGenerators::asStringList($baseline);
            $candidate = DomainGenerators::asStringList($candidate);

            $alignment = StatementAligner::align(baseline: $baseline, candidate: $candidate);

            $baselineSeen = [];
            $candidateSeen = [];
            foreach ($alignment->items as $item) {
                if ($item->baselineIndex !== null) {
                    $baselineSeen[] = $item->baselineIndex;
                }
                if ($item->candidateIndex !== null) {
                    $candidateSeen[] = $item->candidateIndex;
                }
                // Unchanged means unchanged: the fingerprints really match.
                if ($item->kind === AlignmentKind::Unchanged) {
                    self::assertSame($baseline[(int) $item->baselineIndex], $candidate[(int) $item->candidateIndex]);
                }
            }
            sort($baselineSeen);
            sort($candidateSeen);
            self::assertSame(range(0, count($baseline) - 1), $baselineSeen);
            self::assertSame(range(0, count($candidate) - 1), $candidateSeen);
        });
    }

    public function testRemovingOneStatementFromARepeatFreeSequenceReportsExactlyOneRemoval(): void
    {
        $this->forAll(
            Generator\choose(1, 6),
            Generator\choose(0, 1000),
        )->then(function (int $length, int $positionSeed): void {
            $baseline = array_map(fn (int $i): string => sprintf('SELECT %d', $i), range(1, $length));
            $removed = $positionSeed % $length;
            $candidate = array_values(array_diff_key($baseline, [$removed => true]));

            $alignment = StatementAligner::align(baseline: $baseline, candidate: $candidate);

            self::assertSame([$removed], $alignment->baselineIndices(AlignmentKind::Removed));
            self::assertSame([], $alignment->baselineIndices(AlignmentKind::Ambiguous));
            self::assertSame([], $alignment->baselineIndices(AlignmentKind::Modified));
            self::assertCount($length - 1, $alignment->baselineIndices(AlignmentKind::Unchanged));
            self::assertCount(1, $alignment->changes());
        });
    }

    /**
     * Sequences of up to six fingerprints drawn from four shapes, so repeats
     * are common.
     *
     * @return Generator<mixed>
     */
    private function fingerprints(): Generator
    {
        return DomainGenerators::boundedList(elements: Generator\elements('A', 'B', 'C', 'D'), maxLength: 6);
    }
}
