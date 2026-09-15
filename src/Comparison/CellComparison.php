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
     * @param list<StatementInstability> $unstableStatements positions that diverged within either run
     * @param list<StatementPlanComparison> $planChanges the plans behind every aligned change, in alignment order
     * @param list<BlockComparison> $blocks per mirrored block pair, in block order
     */
    public function __construct(
        public readonly ScenarioName $scenario,
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly int $baselineStatementCount,
        public readonly int $candidateStatementCount,
        public readonly ResultSetComparison $resultSets,
        public readonly StatementAlignment $alignment,
        public readonly int $baselineSampleCount,
        public readonly int $candidateSampleCount,
        public readonly Duration $baselineMedianWall,
        public readonly Duration $candidateMedianWall,
        public readonly Duration $baselineP95Wall,
        public readonly Duration $candidateP95Wall,
        public readonly MedianShift $wallShift,
        public readonly array $unstableStatements,
        public readonly array $planChanges,
        public readonly array $blocks,
    ) {}

    /**
     * True when the aligned statement sequences differ anywhere - a modified,
     * inserted, removed or ambiguously aligned statement.
     */
    public function sqlChanged(): bool
    {
        return $this->alignment->sqlChanged();
    }

    /**
     * True when either run's statement sequence was not identical in every
     * iteration: the positional SQL comparison and the statement timings then
     * describe subsets, and the report says which.
     */
    public function hasUnstableStatements(): bool
    {
        return $this->unstableStatements !== [];
    }

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

    /**
     * True when the per-block effects do not even agree on a direction: some
     * blocks saw the candidate faster, others slower. Such a cell's pooled
     * delta describes noise or an order effect, not the implementation.
     */
    public function blockEffectsDisagree(): bool
    {
        $sawFaster = false;
        $sawSlower = false;
        foreach ($this->blocks as $block) {
            $delta = $block->wallDeltaPct();
            $sawFaster = $sawFaster || $delta < 0.0;
            $sawSlower = $sawSlower || $delta > 0.0;
        }

        return $sawFaster && $sawSlower;
    }
}
