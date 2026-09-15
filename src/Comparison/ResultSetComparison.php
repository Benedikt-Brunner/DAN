<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Lib\Protocol\ResultSet;

/**
 * Did the two implementations return the same thing? Answered before any
 * performance question: a candidate that is faster because it returns
 * different rows, a different order or a different total is a correctness
 * failure, not an improvement. Equivalence also requires each run to have
 * returned the same result in every iteration - a result that varies within
 * one run cannot be equivalent to anything.
 */
final class ResultSetComparison
{
    public function __construct(
        public readonly ResultSet $baseline,
        public readonly ResultSet $candidate,
        public readonly bool $baselineConsistent,
        public readonly bool $candidateConsistent,
    ) {}

    public function equivalent(): bool
    {
        return $this->baselineConsistent && $this->candidateConsistent && $this->baseline->equals($this->candidate);
    }

    public function idsDiffer(): bool
    {
        return !$this->baseline->sameIds($this->candidate);
    }

    /**
     * The same rows in a different order - still a different result.
     */
    public function orderDiffers(): bool
    {
        return $this->baseline->sameIds($this->candidate) && $this->baseline->ids !== $this->candidate->ids;
    }

    public function totalDiffers(): bool
    {
        return $this->baseline->total !== $this->candidate->total;
    }

    /**
     * @return list<RunSlot> runs whose result varied between their own iterations
     */
    public function inconsistentRuns(): array
    {
        $runs = [];
        if (!$this->baselineConsistent) {
            $runs[] = RunSlot::Baseline;
        }
        if (!$this->candidateConsistent) {
            $runs[] = RunSlot::Candidate;
        }

        return $runs;
    }
}
