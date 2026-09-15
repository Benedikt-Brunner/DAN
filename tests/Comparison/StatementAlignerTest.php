<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\AlignedStatement;
use Dan\Harness\Comparison\AlignmentKind;
use Dan\Harness\Comparison\StatementAligner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StatementAlignerTest extends TestCase
{
    public function testIdenticalSequencesAreEntirelyUnchanged(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
            'C',
        ], candidate: [
            'A',
            'B',
            'C',
        ]);

        self::assertFalse($alignment->sqlChanged());
        self::assertSame([
            'unchanged 0->0',
            'unchanged 1->1',
            'unchanged 2->2',
        ], self::describe($alignment->items));
    }

    public function testRemovingOneStatementReportsOneRemovalAndKeepsLaterStatementsUnchanged(): void
    {
        // The whole point: positional comparison would have reported B, C
        // and D as changed because they shifted by one.
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
            'C',
            'D',
        ], candidate: [
            'A',
            'C',
            'D',
        ]);

        self::assertSame([
            'unchanged 0->0',
            'removed 1',
            'unchanged 2->1',
            'unchanged 3->2',
        ], self::describe($alignment->items));
        self::assertSame([1], $alignment->baselineIndices(AlignmentKind::Removed));
    }

    public function testInsertingOneStatementReportsOneInsertion(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'C',
        ], candidate: [
            'A',
            'B',
            'C',
        ]);

        self::assertSame([
            'unchanged 0->0',
            'inserted 1',
            'unchanged 1->2',
        ], self::describe($alignment->items));
        self::assertSame([1], $alignment->candidateIndices(AlignmentKind::Inserted));
    }

    public function testReplacingAStatementIsAModification(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
            'C',
        ], candidate: [
            'A',
            'X',
            'C',
        ]);

        self::assertSame([
            'unchanged 0->0',
            'modified 1->1',
            'unchanged 2->2',
        ], self::describe($alignment->items));
    }

    public function testAModificationBehindAnInsertionKeepsBothOriginalPositions(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
            'C',
        ], candidate: [
            'N',
            'A',
            'X',
            'C',
        ]);

        self::assertSame([
            'inserted 0',
            'unchanged 0->1',
            'modified 1->2',
            'unchanged 2->3',
        ], self::describe($alignment->items));
    }

    public function testUnmatchedRunsArePairedInOrderAndLeftoversAreRemovals(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
            'C',
            'D',
        ], candidate: [
            'X',
            'D',
        ]);

        self::assertSame([
            'modified 0->0',
            'removed 1',
            'removed 2',
            'unchanged 3->1',
        ], self::describe($alignment->items));
    }

    public function testARemovedRepeatOfItsNeighbourIsAmbiguousNotARemoval(): void
    {
        // Either B could be the one that disappeared; the data cannot say.
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
            'B',
        ], candidate: [
            'A',
            'B',
        ]);

        self::assertTrue($alignment->sqlChanged());
        self::assertSame([], $alignment->baselineIndices(AlignmentKind::Removed));
        self::assertCount(1, $alignment->baselineIndices(AlignmentKind::Ambiguous));
        self::assertCount(2, $alignment->baselineIndices(AlignmentKind::Unchanged));
    }

    public function testAModifiedRepeatOfItsNeighbourIsAmbiguous(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'A',
        ], candidate: [
            'A',
            'X',
        ]);

        self::assertSame([], $alignment->baselineIndices(AlignmentKind::Modified));
        self::assertSame([1], $alignment->baselineIndices(AlignmentKind::Ambiguous));
        self::assertSame([1], $alignment->candidateIndices(AlignmentKind::Ambiguous));
    }

    public function testAnUnrelatedNeighbourDoesNotMakeAChangeAmbiguous(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
            'A',
        ], candidate: [
            'A',
            'B',
            'X',
        ]);

        self::assertSame([
            'unchanged 0->0',
            'unchanged 1->1',
            'modified 2->2',
        ], self::describe($alignment->items));
    }

    public function testAReorderIsARemovalPlusAnInsertion(): void
    {
        $alignment = StatementAligner::align(baseline: [
            'A',
            'B',
        ], candidate: [
            'B',
            'A',
        ]);

        self::assertSame([
            'removed 0',
            'unchanged 1->0',
            'inserted 1',
        ], self::describe($alignment->items));
    }

    public function testEmptySequencesAlign(): void
    {
        self::assertSame([], StatementAligner::align(baseline: [], candidate: [])->items);
        self::assertSame(['inserted 0'], self::describe(StatementAligner::align(baseline: [], candidate: ['A'])->items));
        self::assertSame(['removed 0'], self::describe(StatementAligner::align(baseline: ['A'], candidate: [])->items));
    }

    public function testAnAlignedStatementRefusesPositionsThatContradictItsKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AlignedStatement(kind: AlignmentKind::Removed, baselineIndex: null, candidateIndex: 2);
    }

    /**
     * @param list<AlignedStatement> $items
     *
     * @return list<string>
     */
    private static function describe(array $items): array
    {
        return array_map(
            fn (AlignedStatement $item): string => match (true) {
                $item->baselineIndex !== null && $item->candidateIndex !== null => sprintf('%s %d->%d', $item->kind->value, $item->baselineIndex, $item->candidateIndex),
                $item->baselineIndex !== null => sprintf('%s %d', $item->kind->value, $item->baselineIndex),
                default => sprintf('%s %d', $item->kind->value, (int) $item->candidateIndex),
            },
            $items,
        );
    }
}
