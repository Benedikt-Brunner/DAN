<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Measurement;

use Dan\Lib\Time\Duration;
use Dan\Probe\Execution\Measurement\StatementMeasurementAccumulator;
use Dan\Probe\Recorder\RecordedStatement;
use PHPUnit\Framework\TestCase;

final class StatementMeasurementAccumulatorTest extends TestCase
{
    public function testBuildsAnImmutableResultAndDetectsDivergentSql(): void
    {
        $measurement = new StatementMeasurementAccumulator(index: 0, sql: 'SELECT 1');
        $measurement->record(new RecordedStatement(
            sql: 'SELECT 1',
            params: null,
            duration: Duration::fromNs(10),
        ));
        $measurement->record(new RecordedStatement(
            sql: 'SELECT 2',
            params: null,
            duration: Duration::fromNs(20),
        ));

        self::assertSame([
            'index' => 0,
            'sql' => 'SELECT 1',
            'durationsNsSamples' => [
                10,
                20,
            ],
            'divergent' => true,
        ], $measurement->result()->toArray());
    }
}
