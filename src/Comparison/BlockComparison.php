<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\LatencyDelta;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\Statistics;
use Dan\Lib\Time\Duration;

/**
 * Baseline against candidate within one mirrored block pair. The two halves
 * of a pair ran back to back, so their effect is the least drift-affected
 * estimate a session offers; comparing the pairs with each other exposes
 * order effects (whichever slot ran first) and host drift over the session.
 */
final class BlockComparison
{
    public readonly Duration $baselineMedianWall;
    public readonly Duration $candidateMedianWall;

    public function __construct(
        public readonly int $blockIndex,
        public readonly int $baselineExecutionOrder,
        public readonly int $candidateExecutionOrder,
        public readonly SampleCollection $baselineSamples,
        public readonly SampleCollection $candidateSamples,
    ) {
        $this->baselineMedianWall = Statistics::create($baselineSamples)->median();
        $this->candidateMedianWall = Statistics::create($candidateSamples)->median();
    }

    public function baselineRanFirst(): bool
    {
        return $this->baselineExecutionOrder < $this->candidateExecutionOrder;
    }

    public function wallDeltaPct(): float
    {
        return LatencyDelta::percent(baseline: $this->baselineMedianWall, candidate: $this->candidateMedianWall);
    }
}
