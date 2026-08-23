<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measurement\Scheduling;

use Dan\Harness\Measurement\Scheduling\BlockScheduler;
use Dan\Harness\Measurement\Scheduling\MeasurementBlock;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Eris\Generator;
use LogicException;

/**
 * Fairness and mirroring of the block scheduler over the whole
 * (implementations x iterations x blocks) input space. Noise cancellation
 * rests on these: every implementation must run exactly the requested
 * iterations, spread evenly across blocks, in mirrored order so linear drift
 * within a session cancels out of the comparison.
 */
final class BlockSchedulerPropertyTest extends PropertyTestCase
{
    public function testEverySlotRunsExactlyTheRequestedIterations(): void
    {
        $this->forAll($this->plans())->then(function (mixed $plan): void {
            [
                $slots,
                $iterations,
                $blocks,
            ] = self::plan($plan);

            $totals = [];
            foreach ((new BlockScheduler())->schedule(slots: $slots, totalIterations: $iterations, blocks: $blocks) as $block) {
                $totals[$block->slot->value] = ($totals[$block->slot->value] ?? 0) + $block->iterations;
            }

            foreach ($slots as $slot) {
                self::assertSame($iterations, $totals[$slot->value] ?? null);
            }
        });
    }

    public function testEverySlotAppearsExactlyOncePerBlockWithFairBlockSizes(): void
    {
        $this->forAll($this->plans())->then(function (mixed $plan): void {
            [
                $slots,
                $iterations,
                $blocks,
            ] = self::plan($plan);

            $scheduled = (new BlockScheduler())->schedule(slots: $slots, totalIterations: $iterations, blocks: $blocks);

            self::assertCount($blocks * count($slots), $scheduled);

            $sizesByIndex = [];
            foreach ($scheduled as $block) {
                $sizesByIndex[$block->blockIndex][$block->slot->value] = $block->iterations;
            }

            self::assertCount($blocks, $sizesByIndex);
            $sizes = [];
            foreach ($sizesByIndex as $blockSlots) {
                self::assertCount(count($slots), $blockSlots);
                // Within one block, every slot runs the same number of
                // iterations - anything else would bias the comparison.
                self::assertCount(1, array_unique($blockSlots));
                $sizes[] = (int) reset($blockSlots);
            }
            if ($sizes === []) {
                self::fail('The schedule contained no blocks.');
            }

            // Fair split: block sizes differ by at most one iteration, and
            // the remainder lands in the leading blocks.
            self::assertLessThanOrEqual(1, max($sizes) - min($sizes));
            $descending = $sizes;
            rsort($descending);
            self::assertSame($descending, $sizes);
        });
    }

    public function testConsecutiveBlocksMirrorTheSlotOrder(): void
    {
        $this->forAll($this->plans())->then(function (mixed $plan): void {
            [
                $slots,
                $iterations,
                $blocks,
            ] = self::plan($plan);

            $scheduled = (new BlockScheduler())->schedule(slots: $slots, totalIterations: $iterations, blocks: $blocks);

            $orderByIndex = [];
            foreach ($scheduled as $block) {
                $orderByIndex[$block->blockIndex][] = $block->slot;
            }

            foreach ($orderByIndex as $index => $order) {
                $expected = $index % 2 === 0 ? $slots : array_reverse($slots);
                self::assertSame($expected, $order, sprintf('Block %d must %s the slot order.', $index, $index % 2 === 0 ? 'keep' : 'mirror'));
            }
        });
    }

    public function testScheduledIterationsAreNeverNegative(): void
    {
        // blocks may exceed iterations at this layer (the CLI validates the
        // protocol); the schedule must still never go negative - trailing
        // blocks simply run zero iterations.
        $this->forAll(
            $this->slotArrangements(),
            Generator\choose(1, 20),
            Generator\choose(1, 40),
        )->then(function (mixed $slots, int $iterations, int $blocks): void {
            $slots = self::slots($slots);
            foreach ((new BlockScheduler())->schedule(slots: $slots, totalIterations: $iterations, blocks: $blocks) as $block) {
                self::assertInstanceOf(MeasurementBlock::class, $block);
                self::assertGreaterThanOrEqual(0, $block->iterations);
            }
        });
    }

    /**
     * A valid measurement plan: slots plus iterations >= blocks, the shape
     * ProtocolResolver guarantees.
     *
     * @return Generator<mixed>
     */
    private function plans(): Generator
    {
        return Generator\bind(
            Generator\tuple(
                $this->slotArrangements(),
                Generator\choose(1, 400),
            ),
            self::blocksWithinIterations(...),
        );
    }

    /**
     * @param array<mixed> $parts
     *
     * @return Generator<mixed>
     */
    private static function blocksWithinIterations(array $parts): Generator
    {
        $iterations = DomainGenerators::asInt($parts[1]);

        return Generator\map(
            fn (int $blocks): array => [
                $parts[0],
                $iterations,
                $blocks,
            ],
            Generator\choose(1, $iterations),
        );
    }

    /** @return Generator<mixed> */
    private function slotArrangements(): Generator
    {
        return Generator\elements(
            [RunSlot::Baseline],
            [RunSlot::Candidate],
            [
                RunSlot::Baseline,
                RunSlot::Candidate,
            ],
            [
                RunSlot::Candidate,
                RunSlot::Baseline,
            ],
        );
    }

    /**
     * @return array{list<RunSlot>, int, int} slots, iterations, blocks
     */
    private static function plan(mixed $plan): array
    {
        $parts = DomainGenerators::asList($plan);

        return [
            self::slots($parts[0]),
            DomainGenerators::asInt($parts[1]),
            DomainGenerators::asInt($parts[2]),
        ];
    }

    /**
     * @return list<RunSlot>
     */
    private static function slots(mixed $value): array
    {
        $slots = [];
        foreach (DomainGenerators::asList($value) as $slot) {
            if (!$slot instanceof RunSlot) {
                throw new LogicException('Generated value is not a RunSlot.');
            }
            $slots[] = $slot;
        }

        return $slots;
    }
}
