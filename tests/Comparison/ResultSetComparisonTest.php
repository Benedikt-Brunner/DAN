<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\ResultSetComparison;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Lib\Protocol\ResultSet;
use PHPUnit\Framework\TestCase;

final class ResultSetComparisonTest extends TestCase
{
    public function testIdenticalConsistentResultsAreEquivalent(): void
    {
        $comparison = self::compare(baseline: [
            'a',
            'b',
        ], candidate: [
            'a',
            'b',
        ]);

        self::assertTrue($comparison->equivalent());
        self::assertFalse($comparison->idsDiffer());
        self::assertFalse($comparison->orderDiffers());
        self::assertFalse($comparison->totalDiffers());
        self::assertSame([], $comparison->inconsistentRuns());
    }

    public function testReorderedIdsAreAnOrderDifferenceNotAnIdDifference(): void
    {
        $comparison = self::compare(baseline: [
            'a',
            'b',
        ], candidate: [
            'b',
            'a',
        ]);

        self::assertFalse($comparison->equivalent());
        self::assertTrue($comparison->orderDiffers());
        self::assertFalse($comparison->idsDiffer());
    }

    public function testMissingIdsAreAnIdDifference(): void
    {
        $comparison = self::compare(baseline: [
            'a',
            'b',
        ], candidate: ['a'], candidateTotal: 2);

        self::assertFalse($comparison->equivalent());
        self::assertTrue($comparison->idsDiffer());
        self::assertFalse($comparison->orderDiffers());
        self::assertFalse($comparison->totalDiffers(), 'Both reported the same total; only the page differs.');
    }

    public function testAChangedTotalAloneBreaksEquivalence(): void
    {
        $comparison = self::compare(baseline: ['a'], candidate: ['a'], baselineTotal: 10, candidateTotal: 9);

        self::assertFalse($comparison->equivalent());
        self::assertTrue($comparison->totalDiffers());
        self::assertFalse($comparison->idsDiffer());
    }

    public function testEmptyResultsOnBothSidesAreEquivalent(): void
    {
        self::assertTrue(self::compare(baseline: [], candidate: [], baselineTotal: 0, candidateTotal: 0)->equivalent());
    }

    public function testAnInconsistentRunIsNeverEquivalent(): void
    {
        $comparison = new ResultSetComparison(
            baseline: new ResultSet(ids: ['a'], total: 1),
            candidate: new ResultSet(ids: ['a'], total: 1),
            baselineConsistent: false,
            candidateConsistent: true,
        );

        self::assertFalse($comparison->equivalent());
        self::assertSame([RunSlot::Baseline], $comparison->inconsistentRuns());
    }

    /**
     * @param list<string> $baseline
     * @param list<string> $candidate
     */
    private static function compare(array $baseline, array $candidate, ?int $baselineTotal = null, ?int $candidateTotal = null): ResultSetComparison
    {
        return new ResultSetComparison(
            baseline: new ResultSet(ids: $baseline, total: $baselineTotal ?? count($baseline)),
            candidate: new ResultSet(ids: $candidate, total: $candidateTotal ?? count($candidate)),
            baselineConsistent: true,
            candidateConsistent: true,
        );
    }
}
