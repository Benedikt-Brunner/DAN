<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Measurement;

use Dan\Lib\Protocol\ResultSet;
use Dan\Probe\Execution\Measurement\ResultSetAccumulator;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ResultSetAccumulatorTest extends TestCase
{
    public function testRecordsTheFirstResultAndStaysConsistentWhileIterationsAgree(): void
    {
        $accumulator = new ResultSetAccumulator();
        $accumulator->observe(new ResultSet(ids: [
            'a',
            'b',
        ], total: 2));
        $accumulator->observe(new ResultSet(ids: [
            'a',
            'b',
        ], total: 2));

        self::assertSame([
            'a',
            'b',
        ], $accumulator->resultSet()->ids);
        self::assertTrue($accumulator->consistent());
    }

    public function testADifferentOrderInALaterIterationIsInconsistent(): void
    {
        $accumulator = new ResultSetAccumulator();
        $accumulator->observe(new ResultSet(ids: [
            'a',
            'b',
        ], total: 2));
        $accumulator->observe(new ResultSet(ids: [
            'b',
            'a',
        ], total: 2));

        self::assertFalse($accumulator->consistent());
        self::assertSame([
            'a',
            'b',
        ], $accumulator->resultSet()->ids, 'The first iteration remains the recorded result.');
    }

    public function testADifferentTotalInALaterIterationIsInconsistent(): void
    {
        $accumulator = new ResultSetAccumulator();
        $accumulator->observe(new ResultSet(ids: ['a'], total: 10));
        $accumulator->observe(new ResultSet(ids: ['a'], total: 11));

        self::assertFalse($accumulator->consistent());
    }

    public function testInconsistencyIsSticky(): void
    {
        $accumulator = new ResultSetAccumulator();
        $accumulator->observe(new ResultSet(ids: ['a'], total: 1));
        $accumulator->observe(new ResultSet(ids: ['b'], total: 1));
        $accumulator->observe(new ResultSet(ids: ['a'], total: 1));

        self::assertFalse($accumulator->consistent());
    }

    public function testRefusesToReportBeforeAnyIteration(): void
    {
        $this->expectException(LogicException::class);
        (new ResultSetAccumulator())->resultSet();
    }
}
