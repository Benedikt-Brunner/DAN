<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Scheduling;

use InvalidArgumentException;

/**
 * Splits the measured iterations of each implementation into alternating
 * blocks so host-level environment drift (noisy CI neighbors, thermal
 * throttling) hits all implementations roughly equally and cancels out of
 * the comparison. Uses mirrored ordering (A,B / B,A / A,B / ...) so linear
 * drift within a session cancels too.
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
    public function schedule(array $slots, int $totalIterations, int $blocks): array
    {
        if ($slots === []) {
            throw new InvalidArgumentException('At least one implementation is required.');
        }

        $iterationsPerBlock = intdiv($totalIterations, $blocks);
        $remainder = $totalIterations % $blocks;

        $plan = [];
        for ($block = 0; $block < $blocks; ++$block) {
            $iterations = $iterationsPerBlock + ($block < $remainder ? 1 : 0);
            $ordered = $block % 2 === 0 ? $slots : array_reverse($slots);
            foreach ($ordered as $slot) {
                $plan[] = new MeasurementBlock(slot: $slot, iterations: $iterations, blockIndex: $block);
            }
        }

        return $plan;
    }
}
