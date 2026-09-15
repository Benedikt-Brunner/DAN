<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Gate;

use Dan\Harness\Comparison\CellComparison;
use Dan\Harness\Gate\Policy;
use Dan\Harness\Gate\ViolationKind;
use Dan\Harness\Measurement\Result\MedianShift;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;
use Dan\Lib\Time\Duration;
use PHPUnit\Framework\TestCase;

/**
 * The gate decides over distributions: a wall regression needs the shift's
 * interval to exclude zero AND the estimate to exceed the limit. Either one
 * alone is not a finding.
 */
final class PolicyTest extends TestCase
{
    public function testASignificantShiftBeyondTheLimitViolates(): void
    {
        $violations = (new Policy(maxWallRegressionPct: 15.0, failOnSqlChange: false))->evaluate([
            self::cell(shift: new MedianShift(estimatePct: 22.0, lowerPct: 16.0, upperPct: 29.0, confidence: 0.95, resamples: 1000)),
        ]);

        self::assertCount(1, $violations);
        self::assertSame(ViolationKind::WallRegression, $violations[0]->kind);
        self::assertSame(15.0, $violations[0]->limitPct);
    }

    public function testALargeButUncertainShiftDoesNotViolate(): void
    {
        // The point estimate alone would have failed the old gate; the
        // interval says the session was noisy, not that the candidate is slow.
        $violations = (new Policy(maxWallRegressionPct: 15.0, failOnSqlChange: false))->evaluate([
            self::cell(shift: new MedianShift(estimatePct: 22.0, lowerPct: -4.0, upperPct: 48.0, confidence: 0.95, resamples: 1000)),
        ]);

        self::assertSame([], $violations);
    }

    public function testASignificantButSmallShiftDoesNotViolate(): void
    {
        $violations = (new Policy(maxWallRegressionPct: 15.0, failOnSqlChange: false))->evaluate([
            self::cell(shift: new MedianShift(estimatePct: 6.0, lowerPct: 2.0, upperPct: 10.0, confidence: 0.95, resamples: 1000)),
        ]);

        self::assertSame([], $violations);
    }

    public function testAnImprovementNeverViolates(): void
    {
        $violations = (new Policy(maxWallRegressionPct: 0.0, failOnSqlChange: false))->evaluate([
            self::cell(shift: new MedianShift(estimatePct: -30.0, lowerPct: -40.0, upperPct: -20.0, confidence: 0.95, resamples: 1000)),
        ]);

        self::assertSame([], $violations);
    }

    public function testWithoutALatencyLimitOnlySqlChangesCanViolate(): void
    {
        $policy = new Policy(maxWallRegressionPct: null, failOnSqlChange: true);

        $violations = $policy->evaluate([
            self::cell(shift: new MedianShift(estimatePct: 300.0, lowerPct: 250.0, upperPct: 350.0, confidence: 0.95, resamples: 1000), changedIndices: [2]),
        ]);

        self::assertCount(1, $violations);
        self::assertSame(ViolationKind::SqlChanged, $violations[0]->kind);
    }

    /**
     * @param list<int> $changedIndices
     */
    private static function cell(MedianShift $shift, array $changedIndices = []): CellComparison
    {
        return new CellComparison(
            scenario: ScenarioName::fromString('product.deep-read'),
            tier: Tier::S,
            database: new DatabaseTarget(engine: Engine::MySql, version: '8.0'),
            baselineStatementCount: 4,
            candidateStatementCount: 4,
            sqlChanged: $changedIndices !== [],
            changedStatementIndices: $changedIndices,
            baselineSampleCount: 30,
            candidateSampleCount: 30,
            baselineMedianWall: Duration::fromNs(10_000_000),
            candidateMedianWall: Duration::fromNs(10_000_000 * (1 + $shift->estimatePct / 100)),
            baselineP95Wall: Duration::fromNs(12_000_000),
            candidateP95Wall: Duration::fromNs(12_000_000),
            wallShift: $shift,
            divergent: false,
            blocks: [],
        );
    }
}
