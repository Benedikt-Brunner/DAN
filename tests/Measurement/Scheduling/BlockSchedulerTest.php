<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measurement\Scheduling;

use Dan\Harness\Measurement\Scheduling\BlockScheduler;
use Dan\Harness\Measurement\Scheduling\MeasurementBlock;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\Protocol;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlockSchedulerTest extends TestCase
{
    public function testEveryImplementationGetsAllIterations(): void
    {
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [
            RunSlot::Baseline,
            RunSlot::Candidate,
        ], protocol: self::protocol(iterations: 30, blocks: 4));

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
        ], protocol: self::protocol(iterations: 8, blocks: 4));

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
        // The execution order is the block's position in this very sequence
        // - it is what lets a cell artifact tell "candidate ran first" apart
        // from "baseline ran first" within a mirrored pair.
        self::assertSame(range(0, 7), array_map(fn (MeasurementBlock $block) => $block->executionOrder, $plan));
        self::assertSame([
            0,
            0,
            1,
            1,
            2,
            2,
            3,
            3,
        ], array_map(fn (MeasurementBlock $block) => $block->blockIndex, $plan));
    }

    public function testDistributesRemainderIterationsToEarlyBlocks(): void
    {
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [RunSlot::Baseline], protocol: self::protocol(iterations: 10, blocks: 3));

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

        $plan = $scheduler->schedule(slots: [RunSlot::Baseline], protocol: self::protocol(iterations: 30, blocks: 4));

        self::assertCount(4, $plan);
        foreach ($plan as $index => $block) {
            self::assertSame(RunSlot::Baseline, $block->slot);
            self::assertSame($index, $block->blockIndex);
        }
    }

    public function testFirstBlockOfEachSlotRunsCellWarmupOnTopOfBlockWarmup(): void
    {
        // The cell warmup (dataset, buffer pool) happens once per slot; the
        // block warmup (fresh probe process, cold connection) happens in every
        // block. With mirrored ordering the candidate's first block is the
        // first block of the session too - both slots must warm the cell
        // exactly once, wherever that block sits in the schedule.
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [
            RunSlot::Baseline,
            RunSlot::Candidate,
        ], protocol: self::protocol(iterations: 8, blocks: 4, warmup: 5, blockWarmup: 2));

        $warmups = array_map(
            fn (MeasurementBlock $block): string => sprintf('%s:%d', $block->slot->value, $block->warmupIterations),
            $plan,
        );
        self::assertSame([
            'baseline:7',
            'candidate:7',
            'candidate:2',
            'baseline:2',
            'baseline:2',
            'candidate:2',
            'candidate:2',
            'baseline:2',
        ], $warmups);
    }

    public function testZeroBlockWarmupLeavesLaterBlocksCold(): void
    {
        // The protocol may opt out of per-block warmup; the schedule must then
        // reproduce exactly the first-block-only warmup and nothing else.
        $scheduler = new BlockScheduler();

        $plan = $scheduler->schedule(slots: [RunSlot::Baseline], protocol: self::protocol(iterations: 6, blocks: 3, warmup: 4, blockWarmup: 0));

        self::assertSame([
            4,
            0,
            0,
        ], array_map(fn (MeasurementBlock $block): int => $block->warmupIterations, $plan));
    }

    public function testRejectsAnEmptySlotList(): void
    {
        $scheduler = new BlockScheduler();

        $this->expectException(InvalidArgumentException::class);
        $scheduler->schedule(slots: [], protocol: self::protocol(iterations: 10, blocks: 2));
    }

    private static function protocol(int $iterations, int $blocks, int $warmup = 0, int $blockWarmup = 0): Protocol
    {
        return new Protocol(
            databases: [],
            tiers: [],
            warmupIterations: $warmup,
            blockWarmupIterations: $blockWarmup,
            measuredIterations: $iterations,
            blocks: $blocks,
            scenarioFilter: null,
        );
    }
}
