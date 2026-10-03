<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\MedianShift;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;
use Dan\Lib\Time\Duration;

final class CellComparison
{
    /**
     * @param list<int> $changedStatementIndices
     */
    public function __construct(
        public readonly ScenarioName $scenario,
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly int $baselineStatementCount,
        public readonly int $candidateStatementCount,
        public readonly bool $sqlChanged,
        public readonly array $changedStatementIndices,
        public readonly int $baselineSampleCount,
        public readonly int $candidateSampleCount,
        public readonly Duration $baselineMedianWall,
        public readonly Duration $candidateMedianWall,
        public readonly Duration $baselineP95Wall,
        public readonly Duration $candidateP95Wall,
        public readonly MedianShift $wallShift,
        public readonly bool $divergent,
        public readonly BlockComparisonCollection $blocks,
    ) {}

    /**
     * The point estimate of the median shift - the headline number. Its
     * uncertainty lives in wallShift; the gate consults both.
     */
    public function wallDeltaPct(): float
    {
        return $this->wallShift->estimatePct;
    }

    /**
     * p95 of a few dozen samples is essentially the second-largest one and
     * swings wildly between sessions; below this many samples the report
     * labels it indicative.
     */
    public const int RELIABLE_P95_SAMPLES = 100;

    public function p95IsIndicativeOnly(): bool
    {
        return min($this->baselineSampleCount, $this->candidateSampleCount) < self::RELIABLE_P95_SAMPLES;
    }
}
