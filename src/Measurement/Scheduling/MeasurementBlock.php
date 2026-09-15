<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Scheduling;

/**
 * One scheduled dan:execute invocation: which implementation runs, how many
 * warmup iterations precede the measured ones, and how many iterations are
 * measured. The warmup is part of the schedule rather than a decision made
 * while executing, so the full plan is inspectable and testable up front.
 */
final class MeasurementBlock
{
    public function __construct(
        public readonly RunSlot $slot,
        public readonly int $warmupIterations,
        public readonly int $iterations,
        public readonly int $blockIndex,
    ) {}
}
