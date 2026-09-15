<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\AlignedStatement;
use Dan\Harness\Comparison\AlignmentKind;
use Dan\Harness\Comparison\StatementPlanComparison;
use Dan\Harness\Plan\QueryPlan;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Protocol\PlanCapture;
use PHPUnit\Framework\TestCase;

final class StatementPlanComparisonTest extends TestCase
{
    public function testComparesTheFactsOfBothCapturedPlans(): void
    {
        $comparison = new StatementPlanComparison(
            statement: new AlignedStatement(kind: AlignmentKind::Modified, baselineIndex: 1, candidateIndex: 1),
            engine: Engine::MySql,
            baseline: self::plan(accessType: 'ref', key: 'PRIMARY', rows: 1),
            candidate: self::plan(accessType: 'ALL', key: null, rows: 1000),
        );

        self::assertSame('ref product via PRIMARY (~1 rows)', $comparison->describeBaseline());
        self::assertSame('ALL product (~1000 rows)', $comparison->describeCandidate());
        self::assertSame([
            'product: access ref -> ALL',
            'product: index PRIMARY -> none',
            'product: ~1 -> ~1000 rows',
        ], $comparison->materialChanges());
    }

    public function testASideWithoutAPlanIsDescribedNotCompared(): void
    {
        $inserted = new StatementPlanComparison(
            statement: new AlignedStatement(kind: AlignmentKind::Inserted, baselineIndex: null, candidateIndex: 4),
            engine: Engine::MariaDb,
            baseline: null,
            candidate: new QueryPlan(capture: PlanCapture::Unsupported, raw: null),
        );

        self::assertSame('no statement', $inserted->describeBaseline());
        self::assertSame('not explainable', $inserted->describeCandidate());
        self::assertSame([], $inserted->materialChanges());
        self::assertNull($inserted->candidateFacts);
    }

    public function testAFailedCaptureSaysSo(): void
    {
        $comparison = new StatementPlanComparison(
            statement: new AlignedStatement(kind: AlignmentKind::Removed, baselineIndex: 2, candidateIndex: null),
            engine: Engine::MySql,
            baseline: new QueryPlan(capture: PlanCapture::Failed, raw: null),
            candidate: null,
        );

        self::assertSame('plan capture failed', $comparison->describeBaseline());
        self::assertSame([], $comparison->materialChanges());
    }

    private static function plan(string $accessType, ?string $key, int $rows): QueryPlan
    {
        $table = [
            'table_name' => 'product',
            'access_type' => $accessType,
            'rows_examined_per_scan' => $rows,
        ];
        if ($key !== null) {
            $table['key'] = $key;
        }

        return new QueryPlan(capture: PlanCapture::Captured, raw: [
            'query_block' => [
                'select_id' => 1,
                'table' => $table,
            ],
        ]);
    }
}
