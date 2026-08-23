<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measure;

use Dan\Harness\Measurement\Scheduling\BlockScheduler;
use Dan\Harness\Measurement\Scheduling\MeasurementBlock;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use PHPUnit\Framework\TestCase;

final class BlockSchedulerTest extends TestCase
{
    public function testEveryImplementationGetsAllIterations(): void
    {
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [
            RunSlot::Baseline,
            RunSlot::Candidate,
        ], totalIterations: 30, blocks: 4);

        $totals = [];
        foreach ($plan as $block) {
            $slot = $block->slot->value;
            $totals[$slot] = ($totals[$slot] ?? 0) + $block->iterations;
        }
        self::assertSame([
            'baseline' => 30,
            'candidate' => 30,
        ], $totals);
    }

    public function testUsesMirroredOrderingAcrossBlocks(): void
    {
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [
            RunSlot::Baseline,
            RunSlot::Candidate,
        ], totalIterations: 8, blocks: 4);

        $order = array_map(fn (MeasurementBlock $block) => $block->slot, $plan);
        self::assertSame([
            RunSlot::Baseline,
            RunSlot::Candidate,
            RunSlot::Candidate,
            RunSlot::Baseline,
            RunSlot::Baseline,
            RunSlot::Candidate,
            RunSlot::Candidate,
            RunSlot::Baseline,
        ], $order);
    }

    public function testDistributesRemainderIterationsToEarlyBlocks(): void
    {
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [RunSlot::Baseline], totalIterations: 10, blocks: 3);

        $iterations = array_map(fn (MeasurementBlock $block) => $block->iterations, $plan);
        self::assertSame([
            4,
            3,
            3,
        ], $iterations);
        self::assertSame(10, array_sum($iterations));
    }

    public function testSingleImplementationRunsSequentialBlocks(): void
    {
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [RunSlot::Baseline], totalIterations: 30, blocks: 4);

        self::assertCount(4, $plan);
        foreach ($plan as $index => $block) {
            self::assertSame(RunSlot::Baseline, $block->slot);
            self::assertSame($index, $block->blockIndex);
        }
    }
}
