<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Scheduling;

/**
 * One scheduled dan:execute invocation: which implementation runs, how many
 * warmup iterations precede the measured ones, and how many iterations are
 * measured. The warmup is part of the schedule rather than a decision made
 * while executing, so the full plan is inspectable and testable up front.
 *
 * blockIndex is the protocol's block number (0..blocks-1, shared by the
 * baseline and candidate halves of one mirrored pair); executionOrder is the
 * block's position in the session's actual execution sequence for the cell,
 * unique across slots. Both are persisted with the block's samples so a cell
 * artifact can reconstruct the exact schedule it was measured under.
 */
final class MeasurementBlock
{
    public function __construct(
        public readonly RunSlot $slot,
        public readonly int $warmupIterations,
        public readonly int $iterations,
        public readonly int $blockIndex,
        public readonly int $executionOrder,
    ) {}
}
