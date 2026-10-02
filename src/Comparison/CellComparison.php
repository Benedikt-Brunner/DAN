<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;
use Dan\Lib\Time\Duration;

final class CellComparison
{
    /**
     * @param list<int> $changedStatementIndices
     * @param list<BlockComparison> $blocks per mirrored block pair, in block order
     */
    public function __construct(
        public readonly ScenarioName $scenario,
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly int $baselineStatementCount,
        public readonly int $candidateStatementCount,
        public readonly bool $sqlChanged,
        public readonly array $changedStatementIndices,
        public readonly Duration $baselineMedianWall,
        public readonly Duration $candidateMedianWall,
        public readonly Duration $baselineP95Wall,
        public readonly Duration $candidateP95Wall,
        public readonly bool $divergent,
        public readonly array $blocks,
    ) {}

    public function wallDeltaPct(): float
    {
        return LatencyDelta::percent(baseline: $this->baselineMedianWall, candidate: $this->candidateMedianWall);
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
