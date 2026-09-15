<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Measurement;

use Dan\Lib\Protocol\PlanCapture;
use Dan\Lib\Time\Duration;
use Dan\Probe\Execution\Measurement\StatementMeasurementAccumulator;
use Dan\Probe\Execution\Result\CapturedPlan;
use Dan\Probe\Recorder\RecordedStatement;
use PHPUnit\Framework\TestCase;

final class StatementMeasurementAccumulatorTest extends TestCase
{
    public function testAStablePositionObservedInEveryIterationIsNotDivergent(): void
    {
        $measurement = new StatementMeasurementAccumulator(index: 0, sql: 'SELECT 1');
        $measurement->record(self::statement(sql: 'SELECT 1', ns: 10));
        $measurement->record(self::statement(sql: 'SELECT 1', ns: 20));

        self::assertSame([
            'index' => 0,
            'sql' => 'SELECT 1',
            'durationsNsSamples' => [
                10,
                20,
            ],
            'observed' => 2,
            'divergence' => 'none',
            'plan' => null,
        ], $measurement->result(iterations: 2, plan: null)->toArray());
    }

    public function testCarriesTheCapturedPlanWhenOneWasTaken(): void
    {
        $measurement = new StatementMeasurementAccumulator(index: 0, sql: 'SELECT 1');
        $measurement->record(self::statement(sql: 'SELECT 1', ns: 10));

        $result = $measurement->result(iterations: 1, plan: new CapturedPlan(capture: PlanCapture::Captured, plan: ['query_block' => ['select_id' => 1]]))->toArray();

        self::assertSame([
            'capture' => 'captured',
            'raw' => ['query_block' => ['select_id' => 1]],
        ], $result['plan']);
    }

    public function testDifferentSqlAtThePositionIsTextDivergence(): void
    {
        $measurement = new StatementMeasurementAccumulator(index: 0, sql: 'SELECT 1');
        $measurement->record(self::statement(sql: 'SELECT 1', ns: 10));
        $measurement->record(self::statement(sql: 'SELECT 2', ns: 20));

        $result = $measurement->result(iterations: 2, plan: null)->toArray();

        self::assertSame('SELECT 1', $result['sql'], 'The first observed SQL names the position.');
        self::assertSame('text', $result['divergence']);
        self::assertSame(2, $result['observed']);
    }

    public function testAPositionMissingFromSomeIterationsIsPresenceDivergence(): void
    {
        // Observed in 1 of 3 iterations: the timings describe a subset and
        // must be labelled as such, whatever the SQL looked like.
        $measurement = new StatementMeasurementAccumulator(index: 4, sql: 'SELECT 1');
        $measurement->record(self::statement(sql: 'SELECT 1', ns: 10));

        $result = $measurement->result(iterations: 3, plan: null)->toArray();

        self::assertSame('presence', $result['divergence']);
        self::assertSame(1, $result['observed']);
        self::assertSame([10], $result['durationsNsSamples']);
    }

    public function testTextAndPresenceDivergenceAreReportedTogether(): void
    {
        $measurement = new StatementMeasurementAccumulator(index: 1, sql: 'SELECT 1');
        $measurement->record(self::statement(sql: 'SELECT 1', ns: 10));
        $measurement->record(self::statement(sql: 'SELECT 3', ns: 30));

        self::assertSame('text-and-presence', $measurement->result(iterations: 5, plan: null)->toArray()['divergence']);
    }

    private static function statement(string $sql, int $ns): RecordedStatement
    {
        return new RecordedStatement(sql: $sql, params: null, duration: Duration::fromNs($ns));
    }
}
