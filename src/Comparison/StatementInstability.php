<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Lib\Protocol\StatementDivergence;

/**
 * One statement position of one run that did not behave the same in every
 * measured iteration - which run, which position, how it diverged, and in
 * how many of the iterations it was observed at all. Surfaced in the report
 * so a positional SQL comparison and the statement timings are read with
 * the subset they actually describe.
 */
final class StatementInstability
{
    public function __construct(
        public readonly RunSlot $slot,
        public readonly int $index,
        public readonly StatementDivergence $divergence,
        public readonly int $observed,
        public readonly int $iterations,
    ) {}
}
