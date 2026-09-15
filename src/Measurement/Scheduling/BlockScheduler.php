<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Scheduling;

use Dan\Harness\Protocol\Protocol;
use InvalidArgumentException;

/**
 * Splits the measured iterations of each implementation into alternating
 * blocks so host-level environment drift (noisy CI neighbors, thermal
 * throttling) hits all implementations roughly equally and cancels out of
 * the comparison. Uses mirrored ordering (A,B / B,A / A,B / ...) so linear
 * drift within a session cancels too.
 *
 * Every block carries its own warmup: the protocol's per-block warmup runs
 * in every block (each block is a fresh probe process with a cold connection),
 * and the first block of each implementation additionally runs the per-cell
 * warmup that brings the dataset and buffer pool up to temperature.
 *
 * Isolation between implementations is NOT this class's job: each
 * implementation runs against its own database container with its own
 * dataset copy, so blocks never share caches or buffer pools.
 */
final class BlockScheduler
{
    /**
     * @param list<RunSlot> $slots
     *
     * @return list<MeasurementBlock>
     */
    public function schedule(array $slots, Protocol $protocol): array
    {
        if ($slots === []) {
            throw new InvalidArgumentException('At least one implementation is required.');
        }

        $iterationsPerBlock = intdiv($protocol->measuredIterations, $protocol->blocks);
        $remainder = $protocol->measuredIterations % $protocol->blocks;

        $plan = [];
        /** @var array<string, bool> $cellWarmupScheduled */
        $cellWarmupScheduled = [];
        for ($block = 0; $block < $protocol->blocks; ++$block) {
            $iterations = $iterationsPerBlock + ($block < $remainder ? 1 : 0);
            $ordered = $block % 2 === 0 ? $slots : array_reverse($slots);
            foreach ($ordered as $slot) {
                $warmup = $protocol->blockWarmupIterations;
                $firstBlockOfSlot = !isset($cellWarmupScheduled[$slot->value]);
                if ($firstBlockOfSlot) {
                    $warmup += $protocol->warmupIterations;
                    $cellWarmupScheduled[$slot->value] = true;
                }
                $plan[] = new MeasurementBlock(
                    slot: $slot,
                    warmupIterations: $warmup,
                    iterations: $iterations,
                    blockIndex: $block,
                    executionOrder: count($plan),
                    capturePlans: $firstBlockOfSlot,
                );
            }
        }

        return $plan;
    }
}
